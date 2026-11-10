<?php
/**
 * Pure scoring engine, Critic roast generator, lost-revenue calculator, and
 * SMS OTP ownership gate for Kolivri Phone Grader.
 *
 * Pure static methods run without requiring a full WordPress runtime so they
 * can be unit-tested directly via `php tests/test-phone-grader.php`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Kolivri_Grader_Engine {

    const MAX_PICKUP_RINGS         = 25;
    const MAX_AFTER_HOURS          = 20;
    const MAX_IN_CALL_BOOKING      = 20;
    const MAX_LEAD_CAPTURE_CRM     = 20;
    const MAX_MISSED_CALL_RECOVERY = 15;
    const MAX_TOTAL_SCORE          = 100;

    const KOLIVRI_PHONE_DISPLAY = '054-787-5670';
    const KOLIVRI_PHONE_E164    = '+972547875670';
    const KOLIVRI_WA_URL        = 'https://wa.me/972547875670';
    const KOLIVRI_HE_HOME_URL   = 'https://kolivri.com/he/';
    const KOLIVRI_FOLLOW_ME_URL = '/he/follow-me/';
    const KOLIVRI_START_URL     = '/start/';

    /**
     * In-memory OTP store (also mirrored to WP transients when available).
     *
     * @var array<string, array>
     */
    private static $otpStore = array();

    /**
     * Calculate the 5-dimension phone handling score (0 to 100).
     *
     * Dimensions:
     * 1. pickupRings (0-25): 1 ring = 25, 2-3 rings = 18, 4-5 rings = 8, 6+/voicemail = 0
     * 2. afterHoursCoverage (0-20): 24/7 AI/human = 20, WhatsApp auto-reply = 10, nothing = 0
     * 3. inCallBooking (0-20): books calendar slot in-call = 20, "I'll call you back later" = 5, none = 0
     * 4. leadCaptureCrm (0-20): auto-logged CRM + transcript = 20, manual note = 8, memory only = 0
     * 5. missedCallRecoverySeconds (0-15): <30s callback/SMS = 15, <1hr = 7, never = 0
     *
     * @param array $input Associative array of dimension values.
     * @return array Detailed score breakdown, grade, weakest dimensions, and Kolivri 100/100 projection.
     */
    public static function calculateAuditScore(array $input) {
        $pickupPts   = self::scorePickupRings(isset($input['pickupRings']) ? $input['pickupRings'] : null);
        $afterPts    = self::scoreAfterHoursCoverage(isset($input['afterHoursCoverage']) ? $input['afterHoursCoverage'] : null);
        $bookingPts  = self::scoreInCallBooking(isset($input['inCallBooking']) ? $input['inCallBooking'] : null);
        $crmPts      = self::scoreLeadCaptureCrm(isset($input['leadCaptureCrm']) ? $input['leadCaptureCrm'] : null);
        $recoveryPts = self::scoreMissedCallRecovery(isset($input['missedCallRecoverySeconds']) ? $input['missedCallRecoverySeconds'] : null);

        $breakdown = array(
            'pickupRings'               => $pickupPts,
            'afterHoursCoverage'        => $afterPts,
            'inCallBooking'             => $bookingPts,
            'leadCaptureCrm'            => $crmPts,
            'missedCallRecoverySeconds' => $recoveryPts,
        );

        $maxBreakdown = array(
            'pickupRings'               => self::MAX_PICKUP_RINGS,
            'afterHoursCoverage'        => self::MAX_AFTER_HOURS,
            'inCallBooking'             => self::MAX_IN_CALL_BOOKING,
            'leadCaptureCrm'            => self::MAX_LEAD_CAPTURE_CRM,
            'missedCallRecoverySeconds' => self::MAX_MISSED_CALL_RECOVERY,
        );

        $totalScore = array_sum($breakdown);
        $totalScore = max(0, min(self::MAX_TOTAL_SCORE, (int) $totalScore));

        $losses = array();
        foreach ($maxBreakdown as $dim => $maxPts) {
            $lost = $maxPts - $breakdown[$dim];
            if ($lost > 0) {
                $losses[$dim] = $lost;
            }
        }
        arsort($losses);
        $weakestDimensions = array_keys($losses);

        $grade = self::scoreToGrade($totalScore);

        return array(
            'totalScore'        => $totalScore,
            'maxScore'          => self::MAX_TOTAL_SCORE,
            'grade'             => $grade,
            'statusLabelHe'     => self::gradeToHebrewLabel($grade),
            'breakdown'         => $breakdown,
            'maxBreakdown'      => $maxBreakdown,
            'losses'            => $losses,
            'weakestDimensions' => $weakestDimensions,
            'kolivriProjected'  => array(
                'totalScore'     => self::MAX_TOTAL_SCORE,
                'grade'          => 'A+',
                'liftPoints'     => self::MAX_TOTAL_SCORE - $totalScore,
                'forwardingCode' => '*61*',
                'followMeUrl'    => self::KOLIVRI_FOLLOW_ME_URL,
                'startUrl'       => self::KOLIVRI_START_URL,
            ),
        );
    }

    /**
     * Score Dimension 1: pickupRings (0-25 pts).
     * 1 ring = 25, 2-3 rings = 18, 4-5 rings = 8, 6+ / voicemail = 0.
     *
     * @param mixed $val
     * @return int
     */
    public static function scorePickupRings($val) {
        if ($val === null || $val === false || $val === '') {
            return 0;
        }
        if (is_string($val)) {
            $norm = strtolower(trim($val));
            if (in_array($norm, array('1', '1_ring', '1_rings', 'instant', 'kolivri'), true)) {
                return 25;
            }
            if (in_array($norm, array('2_3', '2_3_rings', '2-3', '2-3 rings', '2', '3'), true)) {
                return 18;
            }
            if (in_array($norm, array('4_5', '4_5_rings', '4-5', '4-5 rings', '4', '5'), true)) {
                return 8;
            }
            if (in_array($norm, array('6_plus', '6+', 'voicemail', 'none', 'never', 'missed'), true)) {
                return 0;
            }
        }
        if (is_numeric($val)) {
            $rings = (int) $val;
            if ($rings === 1) {
                return 25;
            }
            if ($rings >= 2 && $rings <= 3) {
                return 18;
            }
            if ($rings >= 4 && $rings <= 5) {
                return 8;
            }
        }
        return 0;
    }

    /**
     * Score Dimension 2: afterHoursCoverage (0-20 pts).
     * 24/7 AI/human = 20, WhatsApp auto-reply = 10, nothing = 0.
     *
     * @param mixed $val
     * @return int
     */
    public static function scoreAfterHoursCoverage($val) {
        if ($val === true || $val === 20) {
            return 20;
        }
        if ($val === 10) {
            return 10;
        }
        if (!is_string($val)) {
            return 0;
        }
        $norm = strtolower(trim($val));
        if (in_array($norm, array('24_7', '24/7', '24_7_ai_human', '24_7_ai', 'ai', 'human_24_7', 'human', 'full', 'kolivri'), true)) {
            return 20;
        }
        if (in_array($norm, array('whatsapp', 'whatsapp_auto', 'whatsapp_auto_reply', 'auto_reply', 'sms_auto'), true)) {
            return 10;
        }
        return 0;
    }

    /**
     * Score Dimension 3: inCallBooking (0-20 pts).
     * Books calendar slot in-call = 20, "I'll call you back later" = 5, none = 0.
     *
     * @param mixed $val
     * @return int
     */
    public static function scoreInCallBooking($val) {
        if ($val === true || $val === 20) {
            return 20;
        }
        if ($val === 5) {
            return 5;
        }
        if (!is_string($val)) {
            return 0;
        }
        $norm = strtolower(trim($val));
        if (in_array($norm, array('calendar', 'in_call_calendar', 'books_slot', 'books_calendar_slot', 'in_call', 'auto_book', 'kolivri'), true)) {
            return 20;
        }
        if (in_array($norm, array('callback', 'call_back_later', 'callback_later', 'ill_call_you_back', 'later', 'callback_promise'), true)) {
            return 5;
        }
        return 0;
    }

    /**
     * Score Dimension 4: leadCaptureCrm (0-20 pts).
     * Auto-logged CRM + transcript = 20, manual note = 8, memory only = 0.
     *
     * @param mixed $val
     * @return int
     */
    public static function scoreLeadCaptureCrm($val) {
        if ($val === true || $val === 20) {
            return 20;
        }
        if ($val === 8) {
            return 8;
        }
        if (!is_string($val)) {
            return 0;
        }
        $norm = strtolower(trim($val));
        if (in_array($norm, array('crm', 'auto_crm', 'auto_logged_crm', 'auto_crm_transcript', 'crm_transcript', 'kolivri'), true)) {
            return 20;
        }
        if (in_array($norm, array('manual', 'manual_note', 'note', 'notes', 'paper', 'whatsapp_self', 'excel'), true)) {
            return 8;
        }
        return 0;
    }

    /**
     * Score Dimension 5: missedCallRecoverySeconds (0-15 pts).
     * <30s callback/SMS = 15, <1hr = 7, never = 0.
     *
     * @param mixed $val
     * @return int
     */
    public static function scoreMissedCallRecovery($val) {
        if ($val === null || $val === false || $val === '') {
            return 0;
        }
        if (is_string($val)) {
            $norm = strtolower(trim($val));
            if (in_array($norm, array('under_30s', '<30s', '<=30s', '30s', 'instant', '0_30s', 'kolivri'), true)) {
                return 15;
            }
            if (in_array($norm, array('under_1h', '<1hr', '<1h', '1h', '1hr', 'within_hour'), true)) {
                return 7;
            }
            if (in_array($norm, array('never', 'none', 'nothing', 'next_day'), true)) {
                return 0;
            }
        }
        if (is_numeric($val)) {
            $sec = (float) $val;
            if ($sec > 0 && $sec <= 30) {
                return 15;
            }
            if ($sec > 30 && $sec <= 3600) {
                return 7;
            }
        }
        return 0;
    }

    /**
     * Map numeric 0-100 score to a letter grade.
     *
     * @param int $score
     * @return string
     */
    public static function scoreToGrade($score) {
        $score = (int) $score;
        if ($score >= 95) {
            return 'A+';
        }
        if ($score >= 85) {
            return 'A';
        }
        if ($score >= 70) {
            return 'B';
        }
        if ($score >= 55) {
            return 'C';
        }
        if ($score >= 35) {
            return 'D';
        }
        return 'F';
    }

    /**
     * Map letter grade to Hebrew status description.
     *
     * @param string $grade
     * @return string
     */
    public static function gradeToHebrewLabel($grade) {
        switch ($grade) {
            case 'A+':
            case 'A':
                return 'מענה מצוין — העסק תופס כמעט כל ליד בזמן אמת';
            case 'B':
                return 'מענה סביר — אך עדיין זולגים לידים רווחיים בשעות העומס והערב';
            case 'C':
                return 'מענה בינוני — לקוחות חמים מתייאשים ועוברים למתחרים בגוגל';
            case 'D':
                return 'מצב מסוכן — רוב השיחות שלא נענות הופכות לעבודות של המתחרה שלך';
            default:
                return 'קריטי — העסק מדמם שיחות וכסף בכל יום על הסולם ובכביש';
        }
    }

    /**
     * Calculate exact monthly lost revenue in ILS (₪) from missed calls.
     *
     * Formula: round(missedCallsPerWeek * 4 weeks * avgJobValueILS * closeRate).
     * Accepts closeRate either as decimal (0.35) or percentage (35).
     *
     * @param float|int $missedCallsPerWeek
     * @param float|int $avgJobValueILS
     * @param float|int $closeRate
     * @return int Exact monthly lost revenue in ILS.
     */
    public static function calculateMonthlyLostRevenueILS($missedCallsPerWeek, $avgJobValueILS, $closeRate = 0.35) {
        $missed = max(0.0, (float) $missedCallsPerWeek);
        $jobVal = max(0.0, (float) $avgJobValueILS);
        $rate   = (float) $closeRate;
        if ($rate > 1.0) {
            $rate = $rate / 100.0;
        }
        $rate = max(0.0, min(1.0, $rate));

        return (int) round($missed * 4.0 * $jobVal * $rate);
    }

    /**
     * Return full revenue leakage breakdown (weekly, 4-week monthly, 4.33-week monthly, annual).
     *
     * @param float|int $missedCallsPerWeek
     * @param float|int $avgJobValueILS
     * @param float|int $closeRate
     * @return array
     */
    public static function calculateRevenueBreakdown($missedCallsPerWeek, $avgJobValueILS, $closeRate = 0.35) {
        $missed = max(0.0, (float) $missedCallsPerWeek);
        $jobVal = max(0.0, (float) $avgJobValueILS);
        $rate   = (float) $closeRate;
        if ($rate > 1.0) {
            $rate = $rate / 100.0;
        }
        $rate = max(0.0, min(1.0, $rate));

        $weeklyLost  = (int) round($missed * $jobVal * $rate);
        $monthlyLost = self::calculateMonthlyLostRevenueILS($missed, $jobVal, $rate);
        $annualLost  = $monthlyLost * 12;

        return array(
            'missedCallsPerWeek' => $missed,
            'avgJobValueILS'     => $jobVal,
            'closeRate'          => $rate,
            'weeklyLostILS'      => $weeklyLost,
            'monthlyLostILS'     => $monthlyLost,
            'annualLostILS'      => $annualLost,
            'formattedMonthlyHe' => '₪' . number_format($monthlyLost),
            'formattedAnnualHe'  => '₪' . number_format($annualLost),
        );
    }

    /**
     * Generate the "Critic Girlfriend" sharp Hebrew & English diagnosis based on the
     * weakest dimensions, monthly lost ILS revenue, and the 100/100 *61* Kolivri lift.
     *
     * @param array $auditInput   5-dimension inputs (and optional revenue parameters).
     * @param array $revenueInput Optional override for missedCallsPerWeek, avgJobValueILS, closeRate.
     * @return array
     */
    public static function generateCriticRoast(array $auditInput, array $revenueInput = array()) {
        $scoreData = isset($auditInput['totalScore'], $auditInput['breakdown'])
            ? $auditInput
            : self::calculateAuditScore($auditInput);

        $missedCalls = isset($revenueInput['missedCallsPerWeek'])
            ? $revenueInput['missedCallsPerWeek']
            : (isset($auditInput['missedCallsPerWeek']) ? $auditInput['missedCallsPerWeek'] : 8);
        $avgJobValue = isset($revenueInput['avgJobValueILS'])
            ? $revenueInput['avgJobValueILS']
            : (isset($auditInput['avgJobValueILS']) ? $auditInput['avgJobValueILS'] : 1200);
        $closeRate   = isset($revenueInput['closeRate'])
            ? $revenueInput['closeRate']
            : (isset($auditInput['closeRate']) ? $auditInput['closeRate'] : 0.35);

        $revenue = self::calculateRevenueBreakdown($missedCalls, $avgJobValue, $closeRate);

        $dimCritiquesHe = array(
            'pickupRings'               => 'הטלפון שלך מצלצל בלי סוף או נופל לתא קולי. כשלקוח עם תקלה דחופה מחכה על הקו 5–6 צלצולים, הוא לא משאיר הודעה — הוא כבר בחיוג למתחרה הבא בגוגל.',
            'afterHoursCoverage'        => 'אחרי שעות העבודה העסק שלך פשוט סגור ללידים. כמעט 40% מהפניות מגיעות בערב ובסופ״ש — ובזמן שאתה נח, המתחרים אוספים את העבודות הכי רווחיות של מחר בבוקר.',
            'inCallBooking'             => '״אני אחזור אליך בערב״ זה משפט המוות של עסקים בישראל. עד שתחזור אליו ב-21:00, כבר תיאמו לו ביקור אצל מישהו שסגר יומן במקום.',
            'leadCaptureCrm'            => 'לרשום מספר על פתק, לשלוח לעצמך ווטסאפ או ״לזכור בראש״ על סולם זה לא CRM — זו מסננת שבה הולכים לאיבוד אלפי שקלים בחודש.',
            'missedCallRecoverySeconds' => 'לחזור לשיחה שלא נענתה אחרי שעה (או בכלל לא) זה לעשות וידוא הריגה לליד. אחרי 30 שניות של שקט, הסיכוי לסגור עסקה צונח ב-80%.',
        );

        $dimCritiquesEn = array(
            'pickupRings'               => 'Your phone rings out to voicemail. A customer with an urgent job will not wait 6 rings—they are already dialing the next competitor on Google.',
            'afterHoursCoverage'        => 'After hours, your business is a brick wall. Nearly 40% of high-intent inquiries arrive in the evening or on weekends while your line goes dead.',
            'inCallBooking'             => '"I\'ll call you back tonight" is where Israeli SMB leads go to die. By 9 PM, that customer has already booked someone who locked in a calendar slot on the call.',
            'leadCaptureCrm'            => 'Jotting a number on a scrap of paper or "keeping it in your head" on a ladder is not a CRM—it is a leaky bucket costing you thousands every month.',
            'missedCallRecoverySeconds' => 'Calling back an hour later—or never—is lead malpractice. After 30 seconds of silence, your close rate drops by 80%.',
        );

        $weakest = $scoreData['weakestDimensions'];
        $bulletsHe = array();
        $bulletsEn = array();

        foreach ($weakest as $dim) {
            if (isset($dimCritiquesHe[$dim])) {
                $bulletsHe[] = $dimCritiquesHe[$dim];
                $bulletsEn[] = $dimCritiquesEn[$dim];
            }
        }

        $totalScore = (int) $scoreData['totalScore'];
        $monthlyFormatted = $revenue['formattedMonthlyHe'];

        if ($totalScore === 100) {
            $headlineHe = 'ציון 100/100 — אפילו למבקרת אין מה להעיר: העסק שלך עונה כמו מכונת מכירות משומנת!';
            $headlineEn = '100/100 — Even the Critic has zero notes: your phone response is airtight.';
            $roastHe    = sprintf(
                'ציון %d/100. מענה בצלצול ראשון, זמינות 24/7, תיאום יומן בשיחה ותיעוד מלא ב-CRM. אם עוד לא הפעלת עקוב אחריי חכם של קוליברי (*61*), ודא שהגיבוי שלך פעיל ב-%s.',
                $totalScore,
                self::KOLIVRI_FOLLOW_ME_URL
            );
            $roastEn    = sprintf(
                'Score %d/100. First-ring pickup, 24/7 coverage, live in-call booking, and automated CRM logging. Keep your *61* Kolivri forwarding active so not a single shekel leaks.',
                $totalScore
            );
        } else {
            $topCritiqueHe = !empty($bulletsHe) ? $bulletsHe[0] : $dimCritiquesHe['pickupRings'];
            $topCritiqueEn = !empty($bulletsEn) ? $bulletsEn[0] : $dimCritiquesEn['pickupRings'];

            $headlineHe = sprintf(
                'ציון %d/100 (%s) — מאמי, אתה לא ״עמוס בעבודה״, אתה פשוט תורם %s בחודש למתחרים שלך.',
                $totalScore,
                $scoreData['grade'],
                $monthlyFormatted
            );
            $headlineEn = sprintf(
                'Score %d/100 (%s) — You aren\'t "too busy," you are donating %s/month straight to your competitors.',
                $totalScore,
                $scoreData['grade'],
                $monthlyFormatted
            );

            $roastHe = sprintf(
                '%s %s בחישוב פשוט (%s שיחות מפוספסות בשבוע × ₪%s עבודה ממוצעת × %d%% סגירה), אתה שורף %s בחודש (₪%s בשנה!). הפעלה של קוליברי ב-30 שניות דרך קוד *61* מקפיצה אותך מיד מ-%d/100 ל-100/100.',
                $headlineHe,
                $topCritiqueHe,
                number_format((float) $revenue['missedCallsPerWeek'], 0),
                number_format((float) $revenue['avgJobValueILS'], 0),
                (int) round($revenue['closeRate'] * 100),
                $monthlyFormatted,
                number_format($revenue['annualLostILS']),
                $totalScore
            );

            $roastEn = sprintf(
                '%s %s At %s missed calls/week × ₪%s avg job × %d%% close rate, you are burning %s/month (₪%s/year). Switching on Kolivri 30-second *61* conditional forwarding lifts your score from %d/100 to 100/100 immediately.',
                $headlineEn,
                $topCritiqueEn,
                number_format((float) $revenue['missedCallsPerWeek'], 0),
                number_format((float) $revenue['avgJobValueILS'], 0),
                (int) round($revenue['closeRate'] * 100),
                $monthlyFormatted,
                number_format($revenue['annualLostILS']),
                $totalScore
            );
        }

        $kolivriLiftSummaryHe = sprintf(
            'איך עוברים מ-%d/100 ל-100/100 ב-30 שניות? מחייגים *61* לעקוב אחריי חכם של קוליברי (%s): מענה אנושי-AI בעברית תוך צלצול אחד, 24/7 כולל ערבים וסופ״ש, קביעת תור ביומן תוך כדי שיחה, תמלול וכרטיס ליד אוטומטי ב-CRM, והודעת ווטסאפ חוזרת תוך פחות מ-30 שניות.',
            $totalScore,
            self::KOLIVRI_FOLLOW_ME_URL
        );

        return array(
            'score'                => $scoreData,
            'headlineHe'           => $headlineHe,
            'headlineEn'           => $headlineEn,
            'roastHe'              => $roastHe,
            'roastEn'              => $roastEn,
            'bulletsHe'            => $bulletsHe,
            'bulletsEn'            => $bulletsEn,
            'monthlyLostILS'       => $revenue['monthlyLostILS'],
            'annualLostILS'        => $revenue['annualLostILS'],
            'revenue'              => $revenue,
            'kolivriLiftSummaryHe' => $kolivriLiftSummaryHe,
            'projectedScore'       => 100,
            'forwardingCode'       => '*61*',
            'ctaFollowMeUrl'       => self::KOLIVRI_FOLLOW_ME_URL,
            'ctaStartUrl'          => self::KOLIVRI_START_URL,
            'contactPhone'         => self::KOLIVRI_PHONE_DISPLAY,
        );
    }

    /**
     * Normalize an Israeli phone number (mobile or landline) to digits-only 05XXXXXXXX / 0XXXXXXXX,
     * or a valid international E.164 number (+[1-9]\d{7,14}).
     * Returns empty string if invalid.
     *
     * @param string $phone
     * @return string
     */
    public static function normalizeIsraeliPhone($phone) {
        $trimmed = trim((string) $phone);
        $hasPlus = (strpos($trimmed, '+') === 0);
        $digits  = preg_replace('/\D+/', '', $trimmed);
        if ($digits === '') {
            return '';
        }
        if (strpos($digits, '9720') === 0) {
            $digits = '0' . substr($digits, 4);
        } elseif (strpos($digits, '972') === 0 && strlen($digits) >= 11 && strlen($digits) <= 12) {
            $digits = '0' . substr($digits, 3);
        }
        if (preg_match('/^0(?:5\d{8}|[23489]\d{7}|7\d{8})$/', $digits)) {
            return $digits;
        }
        if ($hasPlus && strpos($digits, '972') !== 0) {
            $e164 = '+' . $digits;
            if (preg_match('/^\+[1-9]\d{7,14}$/', $e164)) {
                return $e164;
            }
        }
        return '';
    }

    /**
     * Mask a normalized Israeli phone for safe display (e.g., 054-***-5566).
     *
     * @param string $normalizedPhone
     * @return string
     */
    public static function maskPhone($normalizedPhone) {
        $len = strlen($normalizedPhone);
        if ($len < 9) {
            return $normalizedPhone;
        }
        return substr($normalizedPhone, 0, 3) . '-***-' . substr($normalizedPhone, -4);
    }

    /**
     * SWOT Mitigation: Create a 6-digit SMS OTP for self-audit phone ownership verification.
     * Prevents competitors or trolls from triggering live AI mystery-shopper calls to third-party numbers.
     *
     * @param string $phone
     * @param int    $ttlSeconds
     * @return array
     */
    public static function createSelfAuditOtp($phone, $ttlSeconds = 300) {
        $normalized = self::normalizeIsraeliPhone($phone);
        if ($normalized === '') {
            return array(
                'success'   => false,
                'otpSent'   => false,
                'error'     => 'invalid_phone',
                'messageHe' => 'מספר טלפון ישראלי לא תקין. נא להזין מספר נייד תקין (למשל 054-787-5670).',
            );
        }

        $ttl = max(1, (int) $ttlSeconds);
        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $record = array(
            'normalizedPhone' => $normalized,
            'codeHash'        => hash('sha256', $code),
            'createdAt'       => time(),
            'expiresAt'       => time() + $ttl,
            'attempts'        => 0,
            'maxAttempts'     => 5,
            'verified'        => false,
            'verifiedAt'      => null,
        );

        self::saveOtpRecord($normalized, $record, $ttl);

        return array(
            'success'         => true,
            'otpSent'         => true,
            'normalizedPhone' => $normalized,
            'maskedPhone'     => self::maskPhone($normalized),
            'expiresIn'       => $ttl,
            'code'            => $code,
            'messageHe'       => 'קוד אימות בן 6 ספרות נשלח ב-SMS. מטעמי אבטחה ומניעת הטרדות, שיחת בדיקה חיה מתבצעת רק לאחר אימות בעלות על המספר.',
        );
    }

    /**
     * Verify a 6-digit SMS OTP code before allowing a live mystery-shopper self-audit call.
     *
     * @param string $phone
     * @param string $code
     * @return array
     */
    public static function verifySelfAuditOtp($phone, $code) {
        $normalized = self::normalizeIsraeliPhone($phone);
        if ($normalized === '') {
            return array(
                'verified'    => false,
                'callAllowed' => false,
                'error'       => 'invalid_phone',
                'messageHe'   => 'מספר טלפון לא תקין.',
            );
        }

        $record = self::getOtpRecord($normalized);
        if ($record === null) {
            return array(
                'verified'    => false,
                'callAllowed' => false,
                'error'       => 'otp_not_found',
                'messageHe'   => 'לא נמצאה בקשת אימות פעילה למספר זה. בקש קוד SMS חדש.',
            );
        }

        if (time() > $record['expiresAt']) {
            return array(
                'verified'    => false,
                'callAllowed' => false,
                'error'       => 'otp_expired',
                'messageHe'   => 'פג תוקף קוד האימות. אנא שלח קוד חדש.',
            );
        }

        if ($record['attempts'] >= $record['maxAttempts']) {
            return array(
                'verified'    => false,
                'callAllowed' => false,
                'error'       => 'too_many_attempts',
                'messageHe'   => 'חרגת ממספר הניסיונות המותר. אנא בקש קוד אימות חדש.',
            );
        }

        $cleanCode = trim((string) $code);
        if (!preg_match('/^\d{6}$/', $cleanCode) || !hash_equals($record['codeHash'], hash('sha256', $cleanCode))) {
            $record['attempts']++;
            $remainingTtl = max(1, $record['expiresAt'] - time());
            self::saveOtpRecord($normalized, $record, $remainingTtl);

            return array(
                'verified'          => false,
                'callAllowed'       => false,
                'error'             => 'invalid_code',
                'attemptsRemaining' => max(0, $record['maxAttempts'] - $record['attempts']),
                'messageHe'         => 'קוד האימות שגוי. נסה שנית.',
            );
        }

        $record['verified']   = true;
        $record['verifiedAt'] = time();
        $remainingTtl = max(60, $record['expiresAt'] - time());
        self::saveOtpRecord($normalized, $record, $remainingTtl);

        return array(
            'verified'        => true,
            'callAllowed'     => true,
            'normalizedPhone' => $normalized,
            'verifiedAt'      => $record['verifiedAt'],
            'messageHe'       => 'הבעלות על המספר אומתה בהצלחה! שיחת הביקורת החיה של קוליברי מאושרת לחיוג.',
        );
    }

    /**
     * Check whether a phone number currently holds a verified OTP session allowing a live test call.
     *
     * @param string $phone
     * @return bool
     */
    public static function isSelfAuditCallAllowed($phone) {
        $normalized = self::normalizeIsraeliPhone($phone);
        if ($normalized === '') {
            return false;
        }
        $record = self::getOtpRecord($normalized);
        if ($record === null) {
            return false;
        }
        return !empty($record['verified']) && time() <= $record['expiresAt'];
    }

    /**
     * Save OTP record to in-memory store and WordPress transients (when available).
     *
     * @param string $normalizedPhone
     * @param array  $record
     * @param int    $ttl
     */
    private static function saveOtpRecord($normalizedPhone, array $record, $ttl) {
        self::$otpStore[$normalizedPhone] = $record;
        if (function_exists('set_transient')) {
            set_transient('kpg_otp_' . md5($normalizedPhone), $record, (int) $ttl);
        }
    }

    /**
     * Fetch OTP record from in-memory store or WordPress transients.
     *
     * @param string $normalizedPhone
     * @return array|null
     */
    private static function getOtpRecord($normalizedPhone) {
        if (isset(self::$otpStore[$normalizedPhone])) {
            return self::$otpStore[$normalizedPhone];
        }
        if (function_exists('get_transient')) {
            $val = get_transient('kpg_otp_' . md5($normalizedPhone));
            if (is_array($val)) {
                self::$otpStore[$normalizedPhone] = $val;
                return $val;
            }
        }
        return null;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Lolo_Algorithm (PHP)
 * 复刻 JS 版计算流程：
 * - 真太阳时：等式（EoT）+ 经度修正，时区经度用“标准时区经线”(rawOffset/3600*15)，不使用夏令时
 * - 八字：年/月/日/时（近似算法，与 bazi.js 规则一致）
 * - 身强弱：权重同 strength.js（>=50 为“强”）
 * - 映射：强→Empowered_*，弱→Attuned_*（与 /bo/v1/names 返回集合一致）
 */
class Lolo_Algorithm {

    /* --------- 通用：float→int 显式转换，避免 PHP8.1+ Deprecated ---------- */
    /**
     * 规范 float→int 的行为
     * @param mixed  $v
     * @param string $mode round|floor|ceil|truncate
     */
    private static function bo_int($v, string $mode = 'round'): int {
        if (!is_numeric($v)) return 0;
        $f = (float)$v;
        switch ($mode) {
            case 'floor':    return (int) floor($f);
            case 'ceil':     return (int) ceil($f);
            case 'truncate': return (int) ($f >= 0 ? floor($f) : ceil($f));
            case 'round':
            default:         return (int) round($f);
        }
    }

    /* ============== 真太阳时：solarTime.js / calculateTrueSolarTime 对应 ============== */

    /** 均时差（分钟），按日序近似 */
    private static function equation_of_time_minutes(int $year, int $month, int $day): float {
        $dayOfYear = (int) gmdate('z', gmmktime(0,0,0,$month,$day,$year)) + 1;
        $gamma = 2.0 * M_PI * ($dayOfYear - 1) / 365.0;
        return 229.18 * (
            0.000075
            + 0.001868 * cos($gamma)
            - 0.032077 * sin($gamma)
            - 0.014615 * cos(2*$gamma)
            - 0.040849 * sin(2*$gamma)
        );
    }

    /**
     * 计算真太阳时（返回 DateTime，时区同输入本地时区；仅修正“钟点”）
     * @param DateTime $localStd 本地“标准时”（不含 DST，建议用 rawOffset 来确定标准经线）
     * @param float $longitude 站点经度
     * @param int $timezoneOffsetHours 标准时区小时（rawOffset/3600 四舍五入）
     */
    private static function true_solar_time(DateTime $localStd, float $longitude, int $timezoneOffsetHours): DateTime {
        $y = (int)$localStd->format('Y');
        $m = (int)$localStd->format('m');
        $d = (int)$localStd->format('d');
        $H = (int)$localStd->format('G');
        $I = (int)$localStd->format('i');

        $eotMin = self::equation_of_time_minutes($y, $m, $d);           // float (min)
        $standardMeridian   = $timezoneOffsetHours * 15.0;               // 标准经线（度）
        $longitudeCorrection= ($longitude - $standardMeridian) * 4.0;    // 分钟/经度4分
        $totalCorrection    = $longitudeCorrection + $eotMin;            // 总校正（分钟，float）

        // 全程用 float 计算，再显式归整
        $totalMinutes = ((float)($H * 60 + $I)) + $totalCorrection;      // 可能超出 0..1440

        $dt = clone $localStd;
        // 规范到 [0, 1440) 区间，同时修正日期
        if ($totalMinutes < 0.0) {
            $daysBack = (int) ceil((- $totalMinutes) / 1440.0);
            $dt->modify('-' . $daysBack . ' day');
            $totalMinutes += 1440.0 * $daysBack;
        } elseif ($totalMinutes >= 1440.0) {
            $daysFwd = (int) floor($totalMinutes / 1440.0);
            $dt->modify('+' . $daysFwd . ' day');
            $totalMinutes -= 1440.0 * $daysFwd;
        }

        // 小时=向下取整；分钟=对 60 取 fmod 再四舍五入
        $finalH = (int) floor($totalMinutes / 60.0);
        $minuteFloat = fmod($totalMinutes, 60.0);             // 避免 % 导致的隐式转换
        if ($minuteFloat < 0) { $minuteFloat += 60.0; }       // 理论上不该出现，保险
        $finalM = (int) round($minuteFloat);                  // 0..60

        // 处理 59.5→60 进位
        if ($finalM >= 60) {
            $finalM = 0;
            $finalH += 1;
        }
        if ($finalH >= 24) {
            $dt->modify('+1 day');
            $finalH -= 24;
        }

        $dt->setTime($finalH, $finalM, 0);
        return $dt;
    }

    /* =================== 八字：bazi.js 对应（近似节气 + 柱推算） =================== */

    private static $GAN = ['甲','乙','丙','丁','戊','己','庚','辛','壬','癸'];
    private static $ZHI = ['子','丑','寅','卯','辰','巳','午','未','申','酉','戌','亥'];

    // 简化节气日表（与 bazi.js 的近似映射一致）
    private static $SOLAR_TERMS = [
        1 => [[6,'小寒'], [20,'大寒']],
        2 => [[4,'立春'], [19,'雨水']],
        3 => [[6,'惊蛰'], [21,'春分']],
        4 => [[5,'清明'], [20,'谷雨']],
        5 => [[6,'立夏'], [21,'小满']],
        6 => [[6,'芒种'], [21,'夏至']],
        7 => [[7,'小暑'], [23,'大暑']],
        8 => [[8,'立秋'], [23,'处暑']],
        9 => [[8,'白露'], [23,'秋分']],
        10=> [[8,'寒露'], [24,'霜降']],
        11=> [[7,'立冬'], [22,'小雪']],
        12=> [[7,'大雪'], [22,'冬至']],
    ];

    private static function get_solar_term(int $m, int $d): string {
        $terms = self::$SOLAR_TERMS[$m];
        if ($d >= $terms[0][0] && $d < $terms[1][0]) return $terms[0][1];
        if ($d >= $terms[1][0]) return $terms[1][1];
        $last = $m-1 ?: 12;
        return self::$SOLAR_TERMS[$last][1][1];
    }
    private static function month_zhi_index_by_term(string $term): int {
        $map = [
            '立春'=>2,'雨水'=>2,'惊蛰'=>3,'春分'=>3,'清明'=>4,'谷雨'=>4,
            '立夏'=>5,'小满'=>5,'芒种'=>6,'夏至'=>6,'小暑'=>7,'大暑'=>7,
            '立秋'=>8,'处暑'=>8,'白露'=>9,'秋分'=>9,'寒露'=>10,'霜降'=>10,
            '立冬'=>11,'小雪'=>11,'大雪'=>0,'冬至'=>0,'小寒'=>1,'大寒'=>1
        ];
        return $map[$term];
    }
    private static function month_gan_by_year_gan(string $yearGan, string $monthZhi): string {
        $yearGanStart = ['甲'=>2,'乙'=>4,'丙'=>6,'丁'=>8,'戊'=>0,'己'=>2,'庚'=>4,'辛'=>6,'壬'=>8,'癸'=>0];
        $monthZhiIndex= ['寅'=>0,'卯'=>1,'辰'=>2,'巳'=>3,'午'=>4,'未'=>5,'申'=>6,'酉'=>7,'戌'=>8,'亥'=>9,'子'=>10,'丑'=>11];
        $startIndex = $yearGanStart[$yearGan];
        $mIndex     = $monthZhiIndex[$monthZhi];
        $ganIndex   = ($startIndex + $mIndex) % 10;
        return self::$GAN[$ganIndex];
    }
    private static function calc_year_pillar(int $y, int $m, int $d): array {
        if ( ($m==2 && $d<4) || $m==1 ) $y -= 1; // 立春前算上一年
        $ganMap = [ 4=>'甲',5=>'乙',6=>'丙',7=>'丁',8=>'戊',9=>'己',0=>'庚',1=>'辛',2=>'壬',3=>'癸' ];
        $yearGan = $ganMap[$y % 10];
        $zhiIndex = ($y - 3) % 12; if ($zhiIndex===0) $zhiIndex=12;
        return [$yearGan, self::$ZHI[$zhiIndex-1]];
    }
    private static function calc_month_pillar(string $yearGan, int $m, int $d): array {
        $term = self::get_solar_term($m,$d);
        $mZhiIdx = self::month_zhi_index_by_term($term);
        $monthZhi= self::$ZHI[$mZhiIdx];
        $monthGan= self::month_gan_by_year_gan($yearGan, $monthZhi);
        return [$monthGan, $monthZhi];
    }
    private static function calc_day_pillar(DateTime $dt): array {
        $y=(int)$dt->format('Y'); $m=(int)$dt->format('m'); $d=(int)$dt->format('d'); $h=(int)$dt->format('G');
        $target = clone $dt; if ($h===23) $target->modify('+1 day');
        $ty=(int)$target->format('Y'); $tm=(int)$target->format('m'); $td=(int)$target->format('d');
        $doy = (int) gmdate('z', gmmktime(0,0,0,$tm,$td,$ty)) + 1;
        $y1 = $ty - 1;
        $total = $y1*5 + (int)floor($y1/4) + $doy;
        $rem = $total % 60;
        $ganIdx = ($rem % 10); if ($ganIdx===0) $ganIdx=10; $ganIdx = ($ganIdx-1)%10;
        $zhiIdx = ($rem % 12); if ($zhiIdx===0) $zhiIdx=12; $zhiIdx = ($zhiIdx-1)%12;
        return [ self::$GAN[$ganIdx], self::$ZHI[$zhiIdx] ];
    }
    private static function calc_time_pillar(int $hour, int $minute, string $dayGan): array {
        if ($hour===23) $hour = 0;
        $zhiIndex = ((int)floor( ($hour + 1) / 2 )) % 12;
        $timeZhi = self::$ZHI[$zhiIndex];

        $dayGanIndex = array_search($dayGan, self::$GAN, true);
        if ($dayGanIndex === false) { $dayGanIndex = 0; } // 兜底
        $timeGanIndex= ($dayGanIndex * 2 + $zhiIndex) % 10;
        return [ self::$GAN[$timeGanIndex], $timeZhi ];
    }
    private static function calculate_bazi(DateTime $dt): array {
        $Y=(int)$dt->format('Y'); $M=(int)$dt->format('m'); $D=(int)$dt->format('d');
        list($yGan,$yZhi) = self::calc_year_pillar($Y,$M,$D);
        list($mGan,$mZhi) = self::calc_month_pillar($yGan,$M,$D);
        list($dGan,$dZhi) = self::calc_day_pillar($dt);
        list($tGan,$tZhi) = self::calc_time_pillar( (int)$dt->format('G'), (int)$dt->format('i'), $dGan );
        return [
            '年柱'=>$yGan.$yZhi, '月柱'=>$mGan.$mZhi, '日柱'=>$dGan.$dZhi, '时柱'=>$tGan.$tZhi
        ];
    }

    /* =================== 身强弱：strength.js 同权重与判定 =================== */

    private static $FIVE = [
        '甲'=>'木','乙'=>'木','丙'=>'火','丁'=>'火','戊'=>'土','己'=>'土','庚'=>'金','辛'=>'金','壬'=>'水','癸'=>'水',
        '寅'=>'木','卯'=>'木','巳'=>'火','午'=>'火',
        '辰'=>'土','戌'=>'土','丑'=>'土','未'=>'土',
        '申'=>'金','酉'=>'金','亥'=>'水','子'=>'水',
    ];
    private static $GEN = [ '木'=>'火','火'=>'土','土'=>'金','金'=>'水','水'=>'木' ]; // 生我为助
    private static $W   = [ '年干'=>8,'年支'=>8,'月干'=>12,'月支'=>40,'日支'=>12,'时干'=>10,'时支'=>10 ];

    private static function gan_strength(string $dayGan, string $ganOrZhi): int {
        $dayE = self::$FIVE[$dayGan] ?? null;
        $geE  = self::$FIVE[$ganOrZhi] ?? null;
        if (!$dayE || !$geE) return 0;
        if ($dayE === $geE || (self::$GEN[$geE] ?? '') === $dayE) return 1;
        return 0;
    }
    private static function analyze_strength(array $bazi): string {
        $dayGan = mb_substr($bazi['日柱'],0,1);
        $yearGan= mb_substr($bazi['年柱'],0,1);
        $monthGan=mb_substr($bazi['月柱'],0,1);
        $timeGan= mb_substr($bazi['时柱'],0,1);
        $yearZhi= mb_substr($bazi['年柱'],1,1);
        $monthZhi=mb_substr($bazi['月柱'],1,1);
        $dayZhi=  mb_substr($bazi['日柱'],1,1);
        $timeZhi= mb_substr($bazi['时柱'],1,1);

        $score =
            self::gan_strength($dayGan,$yearGan)  * self::$W['年干'] +
            self::gan_strength($dayGan,$monthGan) * self::$W['月干'] +
            self::gan_strength($dayGan,$timeGan)  * self::$W['时干'] +
            self::gan_strength($dayGan,$yearZhi)  * self::$W['年支'] +
            self::gan_strength($dayGan,$monthZhi) * self::$W['月支'] +
            self::gan_strength($dayGan,$dayZhi)   * self::$W['日支'] +
            self::gan_strength($dayGan,$timeZhi)  * self::$W['时支'];

        return ($score >= 50) ? '强' : '弱';
    }

    /* =================== 人格映射（与 /bo/v1/names 列表一致） =================== */

    private static $MAP_EMPOWERED = [
        '甲'=>'Empowered_Jia_Wood_The_Pioneer',
        '乙'=>'Empowered_Yi_Wood_The_Creator',
        '丙'=>'Empowered_Bing_Fire_The_Leader',
        '丁'=>'Empowered_Ding_Fire_The_Radiant',
        '戊'=>'Empowered_Wu_Earth_The_Guardian',
        '己'=>'Empowered_Ji_Earth_The_Nurturer',
        '庚'=>'Empowered_Geng_Metal_The_Challenger',
        '辛'=>'Empowered_Xin_Metal_The_Artisan',
        '壬'=>'Empowered_Ren_Water_The_Visionary',
        '癸'=>'Empowered_Gui_Water_The_Sage',
    ];
    private static $MAP_ATTUNED = [
        '甲'=>'Attuned_Jia_Wood_The_Dreamer',
        '乙'=>'Attuned_Yi_Wood_The_Poet',
        '丙'=>'Attuned_Bing_Fire_The_Spark',
        '丁'=>'Attuned_Ding_Fire_The_Glow',
        '戊'=>'Attuned_Wu_Earth_The_Cultivator',
        '己'=>'Attuned_Ji_Earth_The_Cultivator',
        '庚'=>'Attuned_Geng_Metal_The_Strategist',
        '辛'=>'Attuned_Xin_Metal_The_Refiner',
        '壬'=>'Attuned_Ren_Water_The_Connector',
        '癸'=>'Attuned_Gui_Water_The_Listener',
    ];

    /**
     * 公开入口（签名与 rest.php 完全一致）
     * @param float $lat
     * @param float $lng
     * @param string $birthdayYmd YYYY-MM-DD（本地“标准时”日期）
     * @param string $tz_id Timezone ID（如 America/Vancouver）
     * @param int $raw_off 秒，标准时区偏移（不含 DST）
     * @param int $dst_off 秒，夏令时偏移
     * @param int $hour 0..23，来自 hour_slot 中点
     * @return string persona key
     */
    public static function calculate_persona($lat, $lng, $birthdayYmd, $tz_id, $raw_off, $dst_off, $hour): string {
        $tz_id  = is_string($tz_id) ? trim($tz_id) : '';
        $raw_off = is_numeric($raw_off) ? (int) $raw_off : null;
        $dst_off = is_numeric($dst_off) ? (int) $dst_off : 0;

        $hour = (int) $hour;
        if ($hour < 0) { $hour = 0; }
        if ($hour > 23) { $hour = $hour % 24; }

        $timezoneOffsetHours = 0;
        if ($tz_id !== '') {
            try {
                $tz = new DateTimeZone($tz_id);
            } catch (Exception $e) {
                $tz_id = '';
            }
        }

        if ($tz_id !== '') {
            $tz = new DateTimeZone($tz_id);
            $localStd = new DateTime($birthdayYmd . ' ' . sprintf('%02d:00:00', $hour), $tz);
            if ($raw_off === null) {
                $raw_off = (int) ($tz->getOffset(new DateTime($birthdayYmd . ' 12:00:00', $tz)) - $dst_off);
            }
        } else {
            if ($raw_off === null) {
                $raw_off = (int) round(((float) $lng) / 15.0) * 3600;
            }
            $tz = new DateTimeZone('UTC');
            $localStd = new DateTime($birthdayYmd . ' ' . sprintf('%02d:00:00', $hour), $tz);
            if ($raw_off !== 0) {
                $localStd->modify(sprintf('%+d seconds', $raw_off));
            }
        }

        $timezoneOffsetHours = (int) round(((int) $raw_off) / 3600.0);
        $solar = self::true_solar_time($localStd, (float)$lng, $timezoneOffsetHours);

        // 3) 八字（以真太阳时计算）
        $bazi = self::calculate_bazi($solar);

        // 4) 身强弱
        $strength = self::analyze_strength($bazi); // '强' or '弱'
        $dayGan = mb_substr($bazi['日柱'], 0, 1);

        // 5) 映射到合法 persona key
        if ($strength === '强') {
            return self::$MAP_EMPOWERED[$dayGan] ?? 'Empowered_Jia_Wood_The_Pioneer';
        } else {
            return self::$MAP_ATTUNED[$dayGan] ?? 'Attuned_Jia_Wood_The_Dreamer';
        }
    }

    /**（可选）工具：返回当前 data 目录下可用的人格键 */
    public static function available_personas(): array {
        $dir = plugin_dir_path(__FILE__) . '../data';
        $out = [];
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.php') as $f) {
                $name = basename($f, '.php');
                if (preg_match('/^(Empowered|Attuned)_[A-Za-z]+_(Wood|Fire|Earth|Metal|Water)_The_[A-Za-z]+$/', $name)) {
                    $out[] = $name;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
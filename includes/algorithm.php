<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ==========================================================================
 *  Bo_City_Algorithm
 *  真太阳时 + 节气精确月令 + 子初(23点)起日 —— 对齐专业排盘（如问真八字）
 * ==========================================================================
 *
 *  核心设计（本版）：
 *
 *  1. 年柱（Year）
 *     - 使用 solar_terms_YYYY.json（UTC 节气表）
 *     - 立春(i=2) 为新年起点
 *     - 出生时刻 UTC 在当年立春前 → 仍算上一年干支
 *
 *  2. 月柱（Month）
 *     - 基于 UTC 节气：
 *       立春起为寅月，每两节气一月，顺推到丑月
 *     - 月干由「年干 + 月序」公式计算（甲己丙寅 等规则）
 *
 *  3. 日柱（Day）【本次关键修订】
 *     - 若有经纬度：使用「当地真太阳时」判定跨日：
 *          * 取真太阳时 TST 的日期和时刻；
 *          * 若 TST 小时 >= 23，则视为“次日”；否则为“当日”；
 *          * 用这个“真太阳日”与基准甲子日比对推算日干支。
 *       → 即：日柱的分界线以“真太阳时 23:00”为准，因地点不同而不同，
 *            从而复现“北京/纽约同一民用时间，日柱不同”的现象。
 *     - 若无经纬度：回退为「当地民用时间 + 子时(>=23:00)起日」。
 *     - 日干支通过固定基准日推算：
 *          DAY_GZ_BASE_DATE_UTC + DAY_GZ_BASE_INDEX
 *
 *  4. 时柱（Hour）
 *     - 若有经纬度：使用真太阳时的小时来定地支；
 *     - 若无经纬度：使用民用时间小时；
 *     - 时干由日干推算（标准公式）。
 *
 *  5. 身强 / 身弱（Strength）
 *     - 使用柱位权重总分制（直接用权重，不做 0~1 归一）：
 *         年干8, 年支8, 月干12, 月支40, 日支12, 时干10, 时支10
 *     - 仅“同我五行（日主同元素）”和“生日主五行（印）”视为正向助力；
 *     - 每个干/藏干若助力，则加 (权重 × 藏干比例)，否则 0；
 *     - 总分 >= 50 → 身强； < 50 → 身弱。
 *
 *  6. 真太阳时（TST）
 *     - 完全基于出生地时区 & 经度 + EoT 计算；
 *     - 不经过“中国时区中转”，全球通用。
 *
 *  7. 兼容
 *     - Lolo_Algorithm::calculate_persona(...) 保留旧接口，只返回 persona_key。
 * ==========================================================================
 */

class Bo_City_Algorithm
{
    /* ====================== 可配置常量：日柱基准 ====================== */

    // 1900-01-31 甲子日，对齐主流排盘系统
    private const DAY_GZ_BASE_DATE_UTC  = '1900-01-31 00:00:00';
    private const DAY_GZ_BASE_INDEX     = 0; // 0=甲子

    /* ============================= 基础表 ============================= */

    private static $GAN = ['甲','乙','丙','丁','戊','己','庚','辛','壬','癸'];
    private static $ZHI = ['子','丑','寅','卯','辰','巳','午','未','申','酉','戌','亥'];

    // 天干 -> 五行
    private static $GAN_WUXING = [
        '甲'=>'木','乙'=>'木',
        '丙'=>'火','丁'=>'火',
        '戊'=>'土','己'=>'土',
        '庚'=>'金','辛'=>'金',
        '壬'=>'水','癸'=>'水',
    ];

    // 五行生 / 克
    private static $WX_GEN  = ['木'=>'火','火'=>'土','土'=>'金','金'=>'水','水'=>'木'];
    private static $WX_CTRL = ['木'=>'土','火'=>'金','土'=>'水','金'=>'木','水'=>'火'];

    // 地支藏干 + 通行比例
    private static $CANG_GAN = [
        '子'=>[['癸',1.0]],
        '丑'=>[['己',0.6],['癸',0.3],['辛',0.1]],
        '寅'=>[['甲',0.7],['丙',0.2],['戊',0.1]],
        '卯'=>[['乙',1.0]],
        '辰'=>[['戊',0.7],['乙',0.2],['癸',0.1]],
        '巳'=>[['丙',0.5],['戊',0.3],['庚',0.2]],
        '午'=>[['丁',0.7],['己',0.3]],
        '未'=>[['己',0.6],['丁',0.2],['乙',0.2]],
        '申'=>[['庚',0.7],['壬',0.2],['戊',0.1]],
        '酉'=>[['辛',1.0]],
        '戌'=>[['戊',0.7],['辛',0.2],['丁',0.1]],
        '亥'=>[['壬',0.8],['甲',0.2]],
    ];

    // 身强权重（总和=100）
    private static $STRENGTH_WEIGHTS = [
        'yg'=>8,   // 年干
        'yz'=>8,   // 年支
        'mg'=>12,  // 月干
        'mz'=>40,  // 月支
        'dz'=>12,  // 日支
        'hg'=>10,  // 时干
        'hz'=>10,  // 时支
    ];

    /* ============================= 主入口 ============================= */

    /**
     * @param array $in
     *  - birth_date: "YYYY-MM-DD"
     *  - hour_slot: "HH:MM"（优先使用，支持旧格式）
     *  - shichen_index: 0..11 (子=0 丑=1 ... 亥=11)
     *  - lat, lng: float 可选（有则启用真太阳时）
     *  - time_zone_id: string (IANA)
     *  - raw_offset: int|null 标准偏移(秒，不含DST)
     *  - dst_offset: int|null DST 偏移(秒)
     */
    public static function calculate_persona(array $in): array
    {
        self::debug('INPUT', $in);

        $birth_date = $in['birth_date'] ?? null;
        $shi        = isset($in['shichen_index']) ? (int)$in['shichen_index'] : null;
        $lat        = isset($in['lat']) ? (float)$in['lat'] : null;
        $lng        = isset($in['lng']) ? (float)$in['lng'] : null;
        $tz_id      = $in['time_zone_id'] ?? null;
        $raw_off    = isset($in['raw_offset']) ? (int)$in['raw_offset'] : null;
        $dst_off    = isset($in['dst_offset']) ? (int)$in['dst_offset'] : 0;
        $hour_slot  = $in['hour_slot']    ?? ($in['birth_time'] ?? null);

        if (!$birth_date || !$tz_id) {
            return ['error' => 'missing_parameters'];
        }

        try {
            $tz = new \DateTimeZone($tz_id);
        } catch (\Exception $e) {
            return ['error' => 'invalid_timezone'];
        }

        $hour = null;
        $minute = null;
        $parsedSlot = null;
        if (is_string($hour_slot) && trim($hour_slot) !== '') {
            $parsedSlot = self::parse_hour_slot($hour_slot);
        }

        if (is_array($parsedSlot)) {
            [$hour, $minute, $slotShi] = $parsedSlot;
            if ($shi === null) {
                $shi = $slotShi;
            }
        }

        if ($hour === null || $minute === null) {
            if ($shi === null) {
                [$hour, $minute, $shi] = self::default_hour_slot();
            } else {
                list($hour, $minute) = self::shichen_to_hour_minute($shi);
            }
        } elseif ($shi === null) {
            $shi = self::shichen_index_from_time($hour, $minute);
        }

        [$hour, $minute] = self::normalize_hour_minute($hour, $minute);

        self::debug('TIME_RESOLVED', [
            'raw_hour_slot' => $hour_slot,
            'parsed_slot'   => $parsedSlot,
            'hour'          => $hour,
            'minute'        => $minute,
            'shichen_index' => $shi,
        ]);

        $civil = \DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $birth_date . sprintf(' %02d:%02d:00', $hour, $minute),
            $tz
        );
        if (!$civil) {
            return ['error' => 'invalid_birth_date'];
        }

        // 2) UTC（节气用）
        $utc_birth = clone $civil;
        $utc_birth->setTimezone(new \DateTimeZone('UTC'));

        // 3) 是否有经纬度
        $hasCoord = ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng));

        // 4) 真太阳时（有经纬度则精算；否则退回 civil）
        if ($hasCoord) {
            $tst_local = self::compute_true_solar_time($civil, (float)$lat, (float)$lng, $tz, $raw_off, $dst_off);
        } else {
            $tst_local = clone $civil;
        }

        self::debug('TIME_SCALES', [
            'civil'     => $civil->format('Y-m-d H:i:s P'),
            'utc'       => $utc_birth->format('Y-m-d H:i:s P'),
            'tst_local' => $tst_local->format('Y-m-d H:i:s P'),
            'has_coord' => $hasCoord ? 1 : 0,
            'tz_id'     => $tz_id,
        ]);

        // 5) 四柱计算
        $gz = self::calculate_bazi($civil, $tst_local, $utc_birth, $hasCoord);
        self::debug('GANZHI', $gz);

        // 6) 身强弱
        $strength = self::analyze_strength($gz);
        self::debug('STRENGTH', $strength);

        // 7) 人格 key
        $persona_key = self::build_persona_key($gz['day_gan'], $strength['label']);
        self::debug('PERSONA_KEY', $persona_key);

        $result = [
            'normalized_hour_slot'  => sprintf('%02d:%02d', $hour, $minute),
            'shichen_index'         => $shi,
            'true_solar_time_local' => $tst_local->format('Y-m-d H:i:s'),
            'gan_zhi' => [
                'year'  => $gz['year_gan']  . $gz['year_zhi'],
                'month' => $gz['month_gan'] . $gz['month_zhi'],
                'day'   => $gz['day_gan']   . $gz['day_zhi'],
                'hour'  => $gz['hour_gan']  . $gz['hour_zhi'],
            ],
            'strength'    => $strength,
            'persona_key' => $persona_key,
            'detail'      => $gz,
        ];

        self::debug('OUTPUT', $result);

        return $result;
    }

    /* ====================== 真太阳时计算（用于日/时判断） ====================== */

    private static function compute_true_solar_time(
        \DateTime $civil,
        float $lat,
        float $lng,
        \DateTimeZone $tz,
        ?int $raw_off,
        int $dst_off
    ): \DateTime {
        // civil 已含时区 + DST
        $offset = $tz->getOffset($civil);

        // 若未显式给出 raw_offset，则用「当前总偏移 - dst_offset」
        if ($raw_off === null) {
            $raw_off = $offset - $dst_off;
        }

        // 1) 等时差 EoT（基于 UTC）
        $utc = clone $civil;
        $utc->setTimezone(new \DateTimeZone('UTC'));
        $year = (int)$utc->format('Y');
        $dayOfYear = (int)$utc->format('z') + 1; // 1..365/366
        $B = 2.0 * M_PI * ($dayOfYear - 81) / 364.0;
        $EoT = 9.87 * sin(2*$B) - 7.53 * cos($B) - 1.5 * sin($B); // 分钟

        // 2) 经度修正（相对标准子午线）
        $std_long = 15.0 * ($raw_off / 3600.0);
        $delta_long = 4.0 * ($lng - $std_long); // 分钟

        $total_min = $EoT + $delta_long;

        $tst = clone $civil;
        $tst->modify(sprintf('%+d seconds', (int)round($total_min * 60)));

        self::debug('TST_COMPUTE', [
            'civil'       => $civil->format('Y-m-d H:i:s P'),
            'lat'         => $lat,
            'lng'         => $lng,
            'raw_offset'  => $raw_off,
            'dst_offset'  => $dst_off,
            'std_long'    => $std_long,
            'EoT_min'     => round($EoT, 2),
            'delta_long'  => round($delta_long, 2),
            'total_min'   => round($total_min, 2),
            'tst_local'   => $tst->format('Y-m-d H:i:s P'),
        ]);

        return $tst;
    }

    /* =========================== 节气相关 =========================== */

    private static function load_true_terms(int $year): array
    {
        if (!function_exists('plugin_dir_path')) return [];
        $file = plugin_dir_path(__FILE__) . "../data/solar_terms_{$year}.json";
        if (!is_file($file)) return [];
        $json = file_get_contents($file);
        if (!$json) return [];
        $data = json_decode($json, true);
        if (isset($data[$year]) && is_array($data[$year])) return $data[$year];
        if (isset($data[(string)$year]) && is_array($data[(string)$year])) return $data[(string)$year];
        if (isset($data[0]['i'])) return $data;
        return [];
    }

    // 返回：已过最近一个节气 index i (0..23)
    private static function get_term_index_for_utc(\DateTime $utc): int
    {
        $y = (int)$utc->format('Y');
        $ts = $utc->getTimestamp();

        $terms = self::load_true_terms($y);
        if (count($terms) < 24) {
            $terms = array_merge(self::load_true_terms($y-1), $terms, self::load_true_terms($y+1));
        }

        if (!$terms) {
            // 兜底：按月份近似
            $m = (int)$utc->format('n');
            return max(0, min(23, ($m - 1) * 2));
        }

        usort($terms, function($a, $b) {
            return strtotime($a['ts_utc']) <=> strtotime($b['ts_utc']);
        });

        $last = 0;
        foreach ($terms as $row) {
            if (!isset($row['i'], $row['ts_utc'])) continue;
            $t = strtotime($row['ts_utc']);
            if ($t === false) continue;
            if ($ts >= $t) {
                $last = (int)$row['i'];
            } else {
                break;
            }
        }
        return $last % 24;
    }

    private static function passed_li_chun(\DateTime $utc): bool
    {
        $y = (int)$utc->format('Y');
        $terms = self::load_true_terms($y);
        foreach ($terms as $row) {
            if ((int)($row['i'] ?? -1) === 2 && !empty($row['ts_utc'])) {
                return $utc->getTimestamp() >= strtotime($row['ts_utc']);
            }
        }
        $fallback = new \DateTime("$y-02-04 00:00:00", new \DateTimeZone('UTC'));
        return $utc >= $fallback;
    }

    private static function month_zhi_from_term(int $termIdx): int
    {
        // 立春(i=2) 为寅月起点，每两节气一月
        $offset   = ($termIdx - 2 + 24) % 24;
        $monthNo  = intdiv($offset, 2);      // 0..11
        return (2 + $monthNo) % 12;          // 寅=2
    }

    private static function month_gan_from_year(int $yearGanIdx, int $monthZhiIdx): int
    {
        // 甲己丙寅, 乙庚戊寅, 丙辛庚寅, 丁壬壬寅, 戊癸甲寅
        $start = [2,2,4,4,6,6,8,8,0,0];
        $gan0  = $start[$yearGanIdx];
        $delta = ($monthZhiIdx - 2 + 12) % 12;
        return ($gan0 + $delta) % 10;
    }

    /* =========================== 四柱计算 =========================== */

    private static function calculate_bazi(
        \DateTime $civilLocal,
        \DateTime $tstLocal,
        \DateTime $utcBirth,
        bool $hasCoord
    ): array {
        // ===== 年柱 =====
        $y = (int)$utcBirth->format('Y');
        $yearIndex = (($y - 1984) % 60 + 60) % 60; // 1984 甲子年
        if (!self::passed_li_chun($utcBirth)) {
            $yearIndex = ($yearIndex - 1 + 60) % 60;
        }
        $yearGanIdx = $yearIndex % 10;
        $yearZhiIdx = $yearIndex % 12;

        // ===== 月柱 =====
        $termIdx     = self::get_term_index_for_utc($utcBirth);
        $monthZhiIdx = self::month_zhi_from_term($termIdx);
        $monthGanIdx = self::month_gan_from_year($yearGanIdx, $monthZhiIdx);

        // ===== 日柱（关键：真太阳时 23:00 起日，按地点变动）=====
        list($dayGanIdx, $dayZhiIdx) = self::day_ganzhi_by_solar_boundary(
            $civilLocal,
            $tstLocal,
            $hasCoord
        );

        // ===== 时柱（真太阳时 / 或退回民用）=====
        list($hourGanIdx, $hourZhiIdx) = self::hour_ganzhi($tstLocal, $dayGanIdx);

        $result = [
            'year_gan'  => self::$GAN[$yearGanIdx],
            'year_zhi'  => self::$ZHI[$yearZhiIdx],
            'month_gan' => self::$GAN[$monthGanIdx],
            'month_zhi' => self::$ZHI[$monthZhiIdx],
            'day_gan'   => self::$GAN[$dayGanIdx],
            'day_zhi'   => self::$ZHI[$dayZhiIdx],
            'hour_gan'  => self::$GAN[$hourGanIdx],
            'hour_zhi'  => self::$ZHI[$hourZhiIdx],
        ];

        self::debug('GANZHI_INDEX', [
            'year_index'  => $yearIndex,
            'year_gan'    => $yearGanIdx,
            'year_zhi'    => $yearZhiIdx,
            'term_index'  => $termIdx,
            'month_gan'   => $monthGanIdx,
            'month_zhi'   => $monthZhiIdx,
            'day_gan'     => $dayGanIdx,
            'day_zhi'     => $dayZhiIdx,
            'hour_gan'    => $hourGanIdx,
            'hour_zhi'    => $hourZhiIdx,
            'has_coord'   => $hasCoord ? 1 : 0,
        ]);

        return $result;
    }

    /**
     * 日柱：使用“真太阳时 23:00”为跨日界线（有经纬度时）；
     *      无经纬度时退回“民用时间 23:00”为界。
     *
     * 这样就可以复现：
     *  - 同一民用时间在北京/纽约，由于 TST 不同，日柱可能前后相差一天的专业盘行为。
     */
    private static function day_ganzhi_by_solar_boundary(
        \DateTime $civilLocal,
        \DateTime $tstLocal,
        bool $hasCoord
    ): array {
        $utcTz = new \DateTimeZone('UTC');
        $base  = new \DateTime(self::DAY_GZ_BASE_DATE_UTC, $utcTz);
        $idx0  = self::DAY_GZ_BASE_INDEX;

        // 有经纬度 → 用真太阳时；无经纬度 → 用民用时间
        $ref = $hasCoord ? clone $tstLocal : clone $civilLocal;

        $h = (int)$ref->format('G');
        if ($h >= 23) {
            $ref->modify('+1 day'); // 子初(23:00)起新日
        }

        $ref->setTimezone($utcTz);
        $ref->setTime(0,0,0);

        $days = intdiv($ref->getTimestamp() - $base->getTimestamp(), 86400);
        $idx  = ($idx0 + $days) % 60;
        if ($idx < 0) $idx += 60;

        return [$idx % 10, $idx % 12];
    }

    /**
     * 时柱：使用真太阳时的小时来定地支；若无经纬度则等同民用时间。
     */
    private static function hour_ganzhi(\DateTime $tstLocal, int $dayGanIdx): array
    {
        $h = (int)$tstLocal->format('G');
        $branchIndex = (int) floor(($h + 1) / 2) % 12;   // 子时23点起
        $stemIndex   = ((($dayGanIdx % 5) * 2) + $branchIndex) % 10;
        return [$stemIndex, $branchIndex];
    }

    /* =========================== 身强弱分析 =========================== */

    private static function analyze_strength(array $gz): array
    {
        $dayGan = $gz['day_gan'] ?? null;
        if (!$dayGan || !isset(self::$GAN_WUXING[$dayGan])) {
            return ['label' => '身弱', 'score' => 0.0];
        }
        $dayEl = self::$GAN_WUXING[$dayGan];
        $w = self::$STRENGTH_WEIGHTS;

        $scoreSum = 0.0;

        $isSupport = function(string $el) use ($dayEl): bool {
            if ($el === $dayEl) return true;                    // 比劫
            return (self::$WX_GEN[$el] ?? null) === $dayEl;     // 印星：生我
        };

        $add_gan = function(?string $gan, float $weight) use (&$scoreSum,$isSupport) {
            if (!$gan) return;
            $el = self::$GAN_WUXING[$gan] ?? null;
            if ($el && $isSupport($el)) {
                $scoreSum += $weight;
            }
        };

        $add_zhi = function(?string $zhi, float $weight) use (&$scoreSum,$isSupport) {
            if (!$zhi || !isset(self::$CANG_GAN[$zhi])) return;
            $supportRatio = 0.0;
            foreach (self::$CANG_GAN[$zhi] as $cg) {
                [$stem, $ratio] = $cg;
                $el = self::$GAN_WUXING[$stem] ?? null;
                if ($el && $isSupport($el)) {
                    $supportRatio += $ratio;
                }
            }
            if ($supportRatio <= 0) return;
            if ($supportRatio > 1) $supportRatio = 1.0;
            $scoreSum += $weight * $supportRatio;
        };

        // 年柱
        $add_gan($gz['year_gan'] ?? null,  $w['yg']);
        $add_zhi($gz['year_zhi'] ?? null,  $w['yz']);

        // 月柱
        $add_gan($gz['month_gan'] ?? null, $w['mg']);
        $add_zhi($gz['month_zhi'] ?? null, $w['mz']);

        // 日支
        $add_zhi($gz['day_zhi'] ?? null,   $w['dz']);

        // 时柱
        $add_gan($gz['hour_gan'] ?? null,  $w['hg']);
        $add_zhi($gz['hour_zhi'] ?? null,  $w['hz']);

        if ($scoreSum < 0)   $scoreSum = 0.0;
        if ($scoreSum > 100) $scoreSum = 100.0;

        $label = ($scoreSum >= 50.0) ? '身强' : '身弱';

        $result = [
            'label' => $label,
            'score' => round($scoreSum, 2),
        ];

        self::debug('STRENGTH_DETAIL', [
            'day_gan' => $dayGan,
            'score'   => $scoreSum,
            'label'   => $label,
        ]);

        return $result;
    }

    /* =========================== 人格映射 =========================== */

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

    private static function build_persona_key(string $dayGan, string $label): string
    {
        $dayGan = mb_substr($dayGan, 0, 1);
        if ($label === '身弱') {
            return self::$MAP_ATTUNED[$dayGan] ?? self::$MAP_ATTUNED['甲'];
        }
        return self::$MAP_EMPOWERED[$dayGan] ?? self::$MAP_EMPOWERED['甲'];
    }

    /* =========================== 工具函数 =========================== */

    private static function normalize_hour_minute(int $hour, int $minute): array
    {
        if ($minute < 0) {
            $minute = 0;
        } elseif ($minute > 59) {
            $hour += intdiv($minute, 60);
            $minute = $minute % 60;
        }
        $hour = ($hour % 24 + 24) % 24;
        return [$hour, $minute];
    }

    private static function shichen_index_from_time(int $hour, int $minute): int
    {
        [$hour, $minute] = self::normalize_hour_minute($hour, $minute);

        $total = ($hour * 60) + $minute;
        if ($total >= 1380 || $total < 60) {
            return 0;
        }

        $shi = (int) floor(($total - 60) / 120) + 1;
        if ($shi < 0) { $shi = 0; }
        if ($shi > 11) { $shi = 11; }
        return $shi;
    }

    private static function default_hour_slot(): array
    {
        [$hour, $minute] = self::normalize_hour_minute(11, 59);
        return [$hour, $minute, self::shichen_index_from_time($hour, $minute)];
    }

    private static function debug(string $label, $context = null): void
    {
        if (!defined('BO_CP_DEBUG_ALGO') || !BO_CP_DEBUG_ALGO) {
            return;
        }

        $prefix = 'R1D1-' . $label . ' [Bo_City_Algorithm] ';

        if ($context instanceof \DateTimeInterface) {
            $context = $context->format('Y-m-d H:i:s P');
        }

        if (is_array($context) || is_object($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($context === null) {
            $encoded = '';
        } else {
            $encoded = (string) $context;
        }

        error_log($prefix . $encoded);
    }

    private static function parse_hour_slot($slot): ?array
    {
        if (!is_string($slot)) {
            return null;
        }
        $slot = trim($slot);
        if ($slot === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $slot, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            [$hour, $minute] = self::normalize_hour_minute($hour, $minute);
            return [$hour, $minute, self::shichen_index_from_time($hour, $minute)];
        }

        if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $slot, $m)) {
            $a = max(0, min(23, (int) $m[1]));
            $b = max(0, min(23, (int) $m[2]));
            $avg = ($a + $b) / 2.0;
            $hour = (int) floor($avg);
            $minute = (int) round(($avg - $hour) * 60);
            [$hour, $minute] = self::normalize_hour_minute($hour, $minute);
            return [$hour, $minute, self::shichen_index_from_time($hour, $minute)];
        }

        if (preg_match('/^(\d{1,2})$/', $slot, $m)) {
            $hour = (int) $m[1];
            [$hour, $minute] = self::normalize_hour_minute($hour, 0);
            return [$hour, $minute, self::shichen_index_from_time($hour, $minute)];
        }

        return null;
    }

    // 时辰索引 → 民用时间起点（子=23:00，丑=01:00，...）
    private static function shichen_to_hour_minute(int $idx): array
    {
        $idx = ($idx % 12 + 12) % 12;
        $start = [23,1,3,5,7,9,11,13,15,17,19,21];
        $h = $start[$idx];
        if ($h >= 24) $h -= 24;
        return [$h,0];
    }
}

/* =========================== 向后兼容接口 =========================== */

class Lolo_Algorithm
{
    /**
     * 旧接口：仅返回 persona_key
     */
    public static function calculate_persona(
        $lat, $lng, $birthdayYmd, $tz_id, $raw_off, $dst_off, $hour
    ): string {
        $lat = is_numeric($lat) ? (float)$lat : null;
        $lng = is_numeric($lng) ? (float)$lng : null;
        $birthdayYmd = trim((string)$birthdayYmd);
        $tz_id = is_string($tz_id) ? trim($tz_id) : '';
        $raw_off = is_numeric($raw_off) ? (int)$raw_off : null;
        $dst_off = is_numeric($dst_off) ? (int)$dst_off : 0;
        $hour = (int)$hour;

        if ($birthdayYmd === '') {
            return 'Empowered_Jia_Wood_The_Pioneer';
        }
        if ($tz_id === '') {
            $tz_id = 'UTC';
        }

        if ($hour < 0)  $hour = 0;
        if ($hour > 23) $hour = $hour % 24;
        $slot = sprintf('%02d:00', $hour);
        $shi = (int) floor(($hour + 1) / 2) % 12;

        $res = Bo_City_Algorithm::calculate_persona([
            'birth_date'    => $birthdayYmd,
            'hour_slot'     => $slot,
            'shichen_index' => $shi,
            'lat'           => $lat,
            'lng'           => $lng,
            'time_zone_id'  => $tz_id,
            'raw_offset'    => $raw_off,
            'dst_offset'    => $dst_off,
        ]);

        return (is_array($res) && !empty($res['persona_key']))
            ? $res['persona_key']
            : 'Empowered_Jia_Wood_The_Pioneer';
    }
}

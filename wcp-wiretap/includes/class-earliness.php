<?php
/**
 * Earliness heuristic (§5) — "how early am I?" per ticker.
 *
 * Two independent axes: social diffusion through YOUR tracked-KOL corpus,
 * and market confirmation (price extension since the first tracked call).
 * The band is a heuristic headline; the facts sentence is the argument —
 * always render both (§3).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPW_Earliness {

    /** Ordered ladder for the originator-share modifier. */
    const LADDER = array('too_early', 'on_time', 'crowded', 'late');

    /**
     * Compute the earliness snapshot for a ticker at evaluation time.
     *
     * @return array { ticker, band, facts, inputs, computed_at }
     */
    public static function compute($ticker) {
        $ticker = strtoupper($ticker);
        $cfg = (array) wcpw_get_setting('earliness');

        $active = WCPW_KOLs::list_by_status('active');
        $n = max(1, count($active));
        $trust_min = (int) wcpw_get_setting('trust_alert_min');

        // --- Mention scan over the trailing 30d corpus (per-tweet signals) ---
        $since_30d = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $rows = WCPW_Tweet_Repo::signal_rows_since($since_30d, $ticker);

        $mentioners = array();       // kol_id => trust
        $total_mentions_30d = 0;
        foreach ($rows as $row) {
            $total_mentions_30d++;
            $kol_id = (int) $row['kol_id'];
            if (!isset($mentioners[$kol_id])) {
                $mentioners[$kol_id] = WCPW_KOLs::trust($kol_id);
            }
        }
        $d_count = count($mentioners);
        $d = $d_count / $n;

        // Small-sample guard (§5): under min_mentions the band is a lie.
        if ($total_mentions_30d < (int) $cfg['min_mentions']) {
            return array(
                'ticker'      => '$' . $ticker,
                'band'        => 'insufficient_data',
                'facts'       => sprintf(
                    '%d mention(s) of $%s across %d tracked KOL(s) in 30d — below the %d-mention floor for a band call.',
                    $total_mentions_30d, $ticker, $d_count, (int) $cfg['min_mentions']
                ),
                'inputs'      => array('d' => round($d, 3), 'v' => null, 'x' => null, 'o' => null, 'total_mentions_30d' => $total_mentions_30d),
                'computed_at' => gmdate('c'),
            );
        }

        // --- Velocity: trust-weighted mentions last 7d vs prior 7d (aggregates table) ---
        $week = WCPW_Themes::weighted_mentions('ticker', $ticker, 7, 0);
        $prior_week = WCPW_Themes::weighted_mentions('ticker', $ticker, 14, 7);
        $v = $week / max($prior_week, 1);

        // --- Price extension anchored to the first tracked call (§3) ---
        $first_call_at = '';
        $price_first = null;
        $registry = WCPW_Ticker_Registry::meta($ticker);
        if ($registry && $registry['first_call_at']) {
            $first_call_at = $registry['first_call_at'];
            $price_first = $registry['price_first'] !== '' ? (float) $registry['price_first'] : null;
        }
        if (!$first_call_at) {
            $earliest = WCPW_Recommendation_Repo::earliest_on_ticker($ticker);
            if ($earliest) {
                $first_call_at = $earliest->post_date_gmt;
                $pac = get_post_meta($earliest->ID, '_wcpw_price_at_call', true);
                $price_first = $pac !== '' ? (float) $pac : null;
            }
        }
        $price_now = WCPW_Price_Source::get_price($ticker);
        $x = ($price_first && $price_now) ? $price_now / $price_first : null;

        // --- Originator share ---
        $tier1_mentioners = 0;
        foreach ($mentioners as $trust) {
            if ($trust >= $trust_min) {
                $tier1_mentioners++;
            }
        }
        $o = $d_count > 0 ? $tier1_mentioners / $d_count : 0;

        $band = self::band($d, $v, $x, $cfg);
        $band = self::apply_originator_modifier($band, $o, $cfg);

        // --- Facts sentence: honest inputs, not fake precision (§3) ---
        $facts = sprintf('%d of %d tracked KOLs mentioned $%s in 30d', $d_count, $n, $ticker);
        if ($first_call_at) {
            $days_ago = max(0, floor((time() - strtotime($first_call_at)) / DAY_IN_SECONDS));
            $facts .= sprintf('; first tracked call %dd ago%s', $days_ago, $price_first ? ' at $' . self::fmt($price_first) : '');
        }
        if ($price_now !== null) {
            $facts .= '; now $' . self::fmt($price_now);
            if ($x !== null) {
                $facts .= sprintf(' (%+.1f%%)', ($x - 1) * 100);
            }
        }
        $facts .= sprintf('; %.1f trust-weighted mentions this week vs %.1f prior', $week, $prior_week);
        $facts .= sprintf('; %d of %d mentioners are tier-1.', $tier1_mentioners, $d_count);

        return array(
            'ticker'      => '$' . $ticker,
            'band'        => $band,
            'facts'       => $facts,
            'inputs'      => array(
                'd' => round($d, 3),
                'v' => round($v, 2),
                'x' => $x !== null ? round($x, 3) : null,
                'o' => round($o, 2),
                'total_mentions_30d' => $total_mentions_30d,
            ),
            'computed_at' => gmdate('c'),
        );
    }

    /**
     * Band rules (§5) — evaluate top-down, first match wins.
     * $x may be null (no price anchor); price conditions involving null fail.
     */
    private static function band($d, $v, $x, array $cfg) {
        $x_flat = ($x !== null && $x >= $cfg['x_flat_lo'] && $x <= $cfg['x_flat_hi']);

        // too_early: d < 0.10 AND v <= 1.0 AND 0.85 <= x <= 1.15
        if ($d < $cfg['d_low'] && $v <= 1.0 && $x_flat) {
            return 'too_early';
        }
        // on_time: 0.10 <= d <= 0.40 AND v > 1.5 AND x < 1.5
        if ($d >= $cfg['d_low'] && $d <= $cfg['d_mid'] && $v > $cfg['v_hot'] && ($x === null || $x < $cfg['x_moved'])) {
            return 'on_time';
        }
        // quiet_mover: d < 0.10 AND x > 1.5 — price moved without your KOLs.
        // Checked before crowded: under literal table order, crowded's
        // 1.5<=x<=3.0 clause would shadow this quadrant (§3: few KOLs +
        // price moved = missed the quiet move, not consensus).
        if ($d < $cfg['d_low'] && $x !== null && $x > $cfg['x_moved']) {
            return 'quiet_mover';
        }
        // late's strong conditions outrank crowded (d>0.70 would otherwise
        // be unreachable behind crowded's d>0.40): d > 0.70 OR x > 3.0
        if ($d > $cfg['d_high'] || ($x !== null && $x > $cfg['x_extended'])) {
            return 'late';
        }
        // crowded: d > 0.40 OR 1.5 <= x <= 3.0
        if ($d > $cfg['d_mid'] || ($x !== null && $x >= $cfg['x_crowded_lo'] && $x <= $cfg['x_crowded_hi'])) {
            return 'crowded';
        }
        // late (fading): v < 0.7 after a 30d mention peak
        if ($v < $cfg['v_cold']) {
            return 'late';
        }
        return 'mixed';
    }

    /**
     * Originator modifier (§5): o >= 0.5 shifts one band earlier;
     * o <= 0.2 shifts one band later. Only within the 4-band ladder —
     * quiet_mover / mixed / insufficient_data are never shifted.
     */
    private static function apply_originator_modifier($band, $o, array $cfg) {
        $idx = array_search($band, self::LADDER, true);
        if ($idx === false) {
            return $band;
        }
        if ($o >= $cfg['o_early'] && $idx > 0) {
            return self::LADDER[$idx - 1];
        }
        if ($o <= $cfg['o_late'] && $idx < count(self::LADDER) - 1) {
            return self::LADDER[$idx + 1];
        }
        return $band;
    }

    /** Human labels + UI tooltip content (failure modes from §3). */
    public static function band_label($band) {
        $labels = array(
            'too_early'         => 'Too early',
            'on_time'           => 'On time',
            'crowded'           => 'Crowded',
            'late'              => 'Late',
            'quiet_mover'       => 'Quiet mover',
            'mixed'             => 'Mixed',
            'insufficient_data' => 'Insufficient data',
        );
        return isset($labels[$band]) ? $labels[$band] : $band;
    }

    public static function failure_modes_tooltip() {
        return 'Heuristic caveats: reflexive assets (memecoins) can stay "crowded" and keep working; '
             . 'thresholds tuned only on winners create survivorship bias; tickers with few mentions are noise; '
             . 'the corpus is blind to anything before you started tracking (archive search partially repairs this).';
    }

    private static function fmt($price) {
        if ($price >= 1000) {
            return number_format($price, 0);
        }
        if ($price >= 1) {
            return number_format($price, 2);
        }
        return rtrim(rtrim(number_format($price, 6), '0'), '.');
    }
}

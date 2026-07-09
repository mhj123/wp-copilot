<?php
/**
 * EDTF (Extended Date/Time Format) — pragmatic 80/20 subset.
 *
 * Supported: YYYY, YYYY-MM, YYYY-MM-DD; qualifiers ~ ? % (circa/uncertain);
 * unspecified digits with X (19XX, 193X); intervals START/END including open
 * ends (../1935, 1935/..). Anything unparseable sorts as "undated" — it is
 * kept, never rejected. Full EDTF Level 2 is deliberately out of scope.
 *
 * Sort keys are zero-padded YYYYMMDD strings so plain lexical meta_value
 * ordering works in WP_Query.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCPO_EDTF {

    /**
     * Sortable range for an EDTF string (single date or interval).
     *
     * @return array { start: 'YYYYMMDD'|'', end: 'YYYYMMDD'|'' } Empty = undated/open.
     */
    public static function to_sort_range($edtf) {
        $edtf = trim((string) $edtf);
        if ($edtf === '') {
            return array('start' => '', 'end' => '');
        }

        if (strpos($edtf, '/') !== false) {
            list($a, $b) = array_pad(explode('/', $edtf, 2), 2, '');
            $pa = self::parse_single($a);
            $pb = self::parse_single($b);
            return array(
                'start' => $pa ? $pa['min'] : '',
                'end'   => $pb ? $pb['max'] : ($pa ? '' : ''),
            );
        }

        $p = self::parse_single($edtf);
        if (!$p) {
            return array('start' => '', 'end' => '');
        }
        return array('start' => $p['min'], 'end' => $p['max']);
    }

    /** True when the string is parseable (open interval ends count as valid). */
    public static function is_valid($edtf) {
        $edtf = trim((string) $edtf);
        if ($edtf === '') {
            return true; // empty = undated, allowed
        }
        if (strpos($edtf, '/') !== false) {
            list($a, $b) = array_pad(explode('/', $edtf, 2), 2, '');
            $a_ok = ($a === '' || $a === '..') || self::parse_single($a) !== null;
            $b_ok = ($b === '' || $b === '..') || self::parse_single($b) !== null;
            return $a_ok && $b_ok && !(($a === '' || $a === '..') && ($b === '' || $b === '..'));
        }
        return self::parse_single($edtf) !== null;
    }

    /**
     * Human-readable rendering: "c. 1932", "12 Mar 1891", "1891–1894",
     * "before 1935", "1900–1999" (for 19XX), "1932?".
     */
    public static function format($edtf) {
        $edtf = trim((string) $edtf);
        if ($edtf === '') {
            return '';
        }

        if (strpos($edtf, '/') !== false) {
            list($a, $b) = array_pad(explode('/', $edtf, 2), 2, '');
            $a_open = ($a === '' || $a === '..');
            $b_open = ($b === '' || $b === '..');
            if ($a_open && !$b_open) {
                return sprintf(__('before %s', 'wcp-openbiografy'), self::format($b));
            }
            if (!$a_open && $b_open) {
                return sprintf(__('from %s', 'wcp-openbiografy'), self::format($a));
            }
            return self::format($a) . '–' . self::format($b);
        }

        $p = self::parse_single($edtf);
        if (!$p) {
            return $edtf; // show the raw string rather than hiding it
        }

        $out = $p['display'];
        if ($p['circa']) {
            $out = 'c. ' . $out;
        }
        if ($p['uncertain']) {
            $out .= '?';
        }
        return $out;
    }

    /** First sortable year, or 0 — used for timeline year gutters. */
    public static function year($edtf) {
        $range = self::to_sort_range($edtf);
        return $range['start'] ? (int) substr($range['start'], 0, 4) : 0;
    }

    /**
     * ISO-ish date for JSON-LD, only when the EDTF is clean (no qualifiers,
     * no X digits, not an interval). Returns '' otherwise — schema.org output
     * must not present fuzzy dates as exact.
     */
    public static function to_iso($edtf) {
        $edtf = trim((string) $edtf);
        if ($edtf === '' || strpos($edtf, '/') !== false || preg_match('/[~?%Xx]/', $edtf)) {
            return '';
        }
        return (preg_match('/^\d{4}(-\d{2}){0,2}$/', $edtf) && self::is_valid($edtf)) ? $edtf : '';
    }

    /**
     * Parse one single EDTF date (no intervals).
     *
     * @return array|null { min: YYYYMMDD, max: YYYYMMDD, circa: bool, uncertain: bool, display: string }
     */
    private static function parse_single($s) {
        $s = trim((string) $s);
        if ($s === '' || $s === '..') {
            return null;
        }

        // Qualifiers may trail the date (or a component) in EDTF; treat any
        // occurrence as qualifying the whole date — coarse but sufficient.
        $circa     = (strpos($s, '~') !== false) || (strpos($s, '%') !== false);
        $uncertain = (strpos($s, '?') !== false) || (strpos($s, '%') !== false);
        $s = str_replace(array('~', '?', '%'), '', $s);

        if (!preg_match('/^([0-9X]{4})(?:-([0-9]{2}))?(?:-([0-9]{2}))?$/i', $s, $m)) {
            return null;
        }

        $year_raw = strtoupper($m[1]);
        $month    = isset($m[2]) ? $m[2] : '';
        $day      = isset($m[3]) ? $m[3] : '';

        if (strpos($year_raw, 'X') !== false) {
            // Unspecified digits: expand to the min/max years they cover.
            if ($month || $day) {
                return null; // 19XX-03 is not worth supporting
            }
            $y_min = (int) str_replace('X', '0', $year_raw);
            $y_max = (int) str_replace('X', '9', $year_raw);
            return array(
                'min'       => sprintf('%04d0101', $y_min),
                'max'       => sprintf('%04d1231', $y_max),
                'circa'     => $circa,
                'uncertain' => $uncertain,
                'display'   => $y_min . '–' . $y_max,
            );
        }

        $year = (int) $year_raw;
        if ($month !== '' && ((int) $month < 1 || (int) $month > 12)) {
            return null;
        }
        if ($day !== '' && ((int) $day < 1 || (int) $day > 31)) {
            return null;
        }

        if ($day !== '') {
            $key = sprintf('%04d%02d%02d', $year, $month, $day);
            $ts  = mktime(12, 0, 0, (int) $month, (int) $day, $year);
            return array(
                'min' => $key, 'max' => $key,
                'circa' => $circa, 'uncertain' => $uncertain,
                'display' => date_i18n('j M Y', $ts),
            );
        }
        if ($month !== '') {
            $ts = mktime(12, 0, 0, (int) $month, 1, $year);
            return array(
                'min' => sprintf('%04d%02d01', $year, $month),
                'max' => sprintf('%04d%02d31', $year, $month),
                'circa' => $circa, 'uncertain' => $uncertain,
                'display' => date_i18n('M Y', $ts),
            );
        }
        return array(
            'min' => sprintf('%04d0101', $year),
            'max' => sprintf('%04d1231', $year),
            'circa' => $circa, 'uncertain' => $uncertain,
            'display' => (string) $year,
        );
    }
}

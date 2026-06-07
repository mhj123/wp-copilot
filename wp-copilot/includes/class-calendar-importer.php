<?php
/**
 * Calendar Importer
 *
 * Parses .ics (iCal) content and stores events in a WP option.
 * Designed for personal Outlook calendar export — handles VEVENT blocks,
 * line folding, DTSTART with TZID, and all-day events.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WCP_Calendar_Importer {

    const OPTION_KEY = 'wcp_calendar_events';

    public static function instance() {
        static $inst = null;
        if ( ! $inst ) $inst = new self();
        return $inst;
    }

    /**
     * Parse raw .ics content and save events to the WP option.
     * Returns number of events stored.
     */
    public function import( $ics_content ) {
        $events = $this->parse_ics( $ics_content );
        update_option( self::OPTION_KEY, wp_json_encode( $events ), false );
        return count( $events );
    }

    /**
     * Return events that fall within [$start_ts, $end_ts] (unix timestamps).
     */
    public function get_events( $start_ts, $end_ts ) {
        $raw = get_option( self::OPTION_KEY, '[]' );
        $all = json_decode( $raw, true ) ?: array();

        return array_values( array_filter( $all, function( $e ) use ( $start_ts, $end_ts ) {
            // Event overlaps window if it starts before window end AND ends after window start
            $e_end = $e['end_ts'] ?? $e['start_ts'];
            return $e['start_ts'] < $end_ts && $e_end > $start_ts;
        } ) );
    }

    /**
     * Parse .ics text into an array of event arrays.
     */
    private function parse_ics( $content ) {
        // Normalise line endings and unfold continuation lines
        $content = str_replace( "\r\n", "\n", $content );
        $content = str_replace( "\r",   "\n", $content );
        $content = preg_replace( '/\n[ \t]/', '', $content ); // unfold

        $lines    = explode( "\n", $content );
        $events   = array();
        $in_event = false;
        $current  = array();

        foreach ( $lines as $line ) {
            $line = rtrim( $line );
            if ( $line === 'BEGIN:VEVENT' ) {
                $in_event = true;
                $current  = array();
                continue;
            }
            if ( $line === 'END:VEVENT' ) {
                $in_event = false;
                if ( ! empty( $current['SUMMARY'] ) ) {
                    $events[] = $current;
                }
                continue;
            }
            if ( ! $in_event ) continue;

            // Split key (with optional params) from value
            $colon = strpos( $line, ':' );
            if ( $colon === false ) continue;

            $key_part = substr( $line, 0, $colon );
            $value    = substr( $line, $colon + 1 );

            // Extract base key (ignore TZID params etc.)
            $base_key = strtoupper( explode( ';', $key_part )[0] );
            $params   = $key_part; // retain full for TZID extraction

            switch ( $base_key ) {
                case 'DTSTART':
                case 'DTEND':
                    $current[ $base_key ] = $this->parse_dt( $value, $params );
                    break;
                case 'SUMMARY':
                    $current['SUMMARY'] = $this->unescape( $value );
                    break;
                case 'DESCRIPTION':
                    $current['DESCRIPTION'] = $this->unescape( $value );
                    break;
                case 'LOCATION':
                    $current['LOCATION'] = $this->unescape( $value );
                    break;
                case 'UID':
                    $current['UID'] = $value;
                    break;
            }
        }

        // Convert to a clean storage format
        $stored = array();
        foreach ( $events as $e ) {
            if ( empty( $e['DTSTART'] ) ) continue;
            $stored[] = array(
                'uid'        => $e['UID'] ?? '',
                'title'      => $e['SUMMARY'] ?? '(No title)',
                'start_ts'   => $e['DTSTART']['ts'],
                'end_ts'     => isset( $e['DTEND'] ) ? $e['DTEND']['ts'] : $e['DTSTART']['ts'] + 3600,
                'all_day'    => $e['DTSTART']['all_day'],
                'location'   => $e['LOCATION'] ?? '',
                'description'=> $e['DESCRIPTION'] ?? '',
            );
        }

        return $stored;
    }

    /**
     * Parse a DTSTART/DTEND value into ['ts' => unix, 'all_day' => bool].
     * Handles: YYYYMMDD, YYYYMMDDTHHMMSS, YYYYMMDDTHHMMSSZ, with or without TZID param.
     */
    private function parse_dt( $value, $key_part ) {
        $all_day = false;

        // Strip VALUE=DATE; param signals all-day
        if ( strpos( $key_part, 'VALUE=DATE' ) !== false ) {
            $all_day = true;
        }

        // All-day format: 8 digits
        if ( preg_match( '/^\d{8}$/', $value ) ) {
            $all_day = true;
            $dt = DateTime::createFromFormat( 'Ymd', $value, wp_timezone() );
            return array( 'ts' => $dt ? $dt->getTimestamp() : 0, 'all_day' => true );
        }

        // Try to extract TZID from key_part (e.g. DTSTART;TZID=Europe/London:...)
        $tzid = null;
        if ( preg_match( '/TZID=([^;:]+)/', $key_part, $m ) ) {
            $tzid = $m[1];
        }

        // Strip trailing Z for UTC handling
        $utc = str_ends_with( $value, 'Z' );
        $value_clean = rtrim( $value, 'Z' );

        try {
            if ( $utc ) {
                $dt = new DateTime( $value_clean, new DateTimeZone( 'UTC' ) );
            } elseif ( $tzid && in_array( $tzid, timezone_identifiers_list() ) ) {
                $dt = new DateTime( $value_clean, new DateTimeZone( $tzid ) );
            } else {
                $dt = new DateTime( $value_clean, wp_timezone() );
            }
            return array( 'ts' => $dt->getTimestamp(), 'all_day' => $all_day );
        } catch ( Exception $e ) {
            return array( 'ts' => strtotime( $value ) ?: 0, 'all_day' => $all_day );
        }
    }

    private function unescape( $value ) {
        return str_replace( array( '\\n', '\\,', '\\;', '\\\\' ), array( "\n", ',', ';', '\\' ), $value );
    }
}

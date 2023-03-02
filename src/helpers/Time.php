<?php

/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\helpers;

/**
 * Helper class for converting time
 */
class Time
{
    /**
     * HH:MM:SS to seconds
     *
     * @param string|null $str_time
     * @return int|null
     */
    public static function time_to_sec(?string $str_time): ?int
    {
        $time_seconds = null;
        if (isset($str_time) && $str_time != '') {
            $strTimeParts = explode(':', $str_time);
            // If passed time is like 00:03, convert it to 00:00:03 (to not be process as 00:03:00)
            // TODO: maybe make this process optional
            if (count($strTimeParts) == 2) {
                $str_time = '00:' . $str_time;
            }
            sscanf($str_time, "%d:%d:%d", $hours, $minutes, $seconds);
            $time_seconds = isset($hours) ? $hours * 3600 + $minutes * 60 + $seconds : $minutes * 60 + $seconds;
        }
        return $time_seconds;
    }

    /**
     * Seconds to HH:MM:SS
     *
     * @param int|null $seconds
     * @return string|null
     */
    public static function sec_to_time(?int $seconds): ?string
    {
        if (isset($seconds)) {
            $seconds = sprintf('%02d:%02d:%02d', ($seconds / 3600), ($seconds / 60 % 60), $seconds % 60);
        }
        return $seconds;
    }
}

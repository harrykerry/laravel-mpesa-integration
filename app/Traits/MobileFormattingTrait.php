<?php

namespace App\Traits;

trait MobileFormattingTrait
{

    /**
     * Sanitizes and formats a mobile number.
     *
     * This function removes all non-numeric characters from the mobile number.
     * If the number starts with '0', it replaces the '0' with '254'.
     *
     * @param string $mobile The raw mobile number input.
     * @return string The sanitized and formatted mobile number.
     */

    public function sanitizeAndFormatMobile(string $mobile): string
    {
        $mobile = preg_replace('/[^0-9]/', '', $mobile);

        if (substr($mobile, 0, 1) === '0') {
            $mobile = '254' . substr($mobile, 1);
        }

        return $mobile;
    }
}

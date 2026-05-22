<?php

namespace Tests\Unit;

use App\Models\CompanyHoliday;
use Tests\TestCase;

class CompanyHolidayIconConfigTest extends TestCase
{
    /**
     * @return void
     */
    public function test_default_icon_is_first_allowed_icon(): void
    {
        $this->assertSame(CompanyHoliday::ALLOWED_ICONS[0], CompanyHoliday::DEFAULT_ICON);
    }

    /**
     * @return void
     */
    public function test_allowed_icons_match_frontend_holiday_icon_options(): void
    {
        $optionsPath = base_path('../front/src/constants/holidayIconOptions.js');
        $this->assertFileExists($optionsPath);

        $contents = file_get_contents($optionsPath);
        preg_match_all("/value: '([^']+)'/", $contents, $matches);
        $frontendIcons = $matches[1] ?? [];

        $this->assertSame(CompanyHoliday::ALLOWED_ICONS, $frontendIcons);
    }
}

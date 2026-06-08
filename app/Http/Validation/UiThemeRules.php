<?php

namespace App\Http\Validation;

class UiThemeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $fontSize = ['nullable', 'string', 'regex:/^\d+(\.\d+)?px$/'];
        $fontFamily = ['nullable', 'string', 'max:200'];
        $fontWeight = ['nullable', 'string', 'regex:/^\d{3}$/'];

        $rules = [
            'ui_theme' => 'nullable|array',
        ];

        $colorKeys = [
            'color_brand',
            'color_brand_hover',
            'color_success',
            'color_success_hover',
            'color_danger',
            'color_danger_hover',
            'color_info',
            'color_warning',
            'colorBrand',
            'colorBrandHover',
            'colorSuccess',
            'colorSuccessHover',
            'colorDanger',
            'colorDangerHover',
            'colorInfo',
            'colorWarning',
        ];

        foreach ($colorKeys as $key) {
            $rules["ui_theme.{$key}"] = $hex;
        }

        $typographyKeys = [
            'font_family_ui' => $fontFamily,
            'font_size_label' => $fontSize,
            'font_size_control' => $fontSize,
            'font_size_table' => $fontSize,
            'font_size_section' => $fontSize,
            'font_weight_label' => $fontWeight,
            'fontFamilyUi' => $fontFamily,
            'fontSizeLabel' => $fontSize,
            'fontSizeControl' => $fontSize,
            'fontSizeTable' => $fontSize,
            'fontSizeSection' => $fontSize,
            'fontWeightLabel' => $fontWeight,
        ];

        foreach ($typographyKeys as $key => $rule) {
            $rules["ui_theme.{$key}"] = $rule;
        }

        return $rules;
    }
}

<?php

if (! function_exists('permission_icon')) {
    function permission_icon($name)
    {
        if (str_ends_with($name, '_view')) {
            return '<i class="fas fa-eye"></i>';
        } elseif (str_ends_with($name, '_create')) {
            return '<i class="fas fa-plus"></i>';
        } elseif (str_ends_with($name, '_edit')) {
            return '<i class="fas fa-pencil-alt"></i>';
        } elseif (str_ends_with($name, '_delete')) {
            return '<i class="fas fa-trash-alt"></i>';
        }
        return '';
    }
}
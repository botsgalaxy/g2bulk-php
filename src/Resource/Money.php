<?php

namespace G2Bulk\Resource;

final class Money
{
    public static function fields($row, array $fields)
    {
        if (!is_array($row)) {
            return $row;
        }
        foreach ($fields as $field) {
            if (isset($row[$field]) && (is_int($row[$field]) || is_float($row[$field]) || is_numeric($row[$field]))) {
                $row[$field] = (float) $row[$field];
            }
        }
        return $row;
    }

    public static function each(array $rows, array $fields): array
    {
        return array_map(function ($row) use ($fields) {
            return self::fields($row, $fields);
        }, $rows);
    }

    public static function product($row)
    {
        return self::fields($row, ['unit_price', 'face_value']);
    }

    public static function products(array $rows): array
    {
        return self::each($rows, ['unit_price', 'face_value']);
    }

    public static function gameOrder($row)
    {
        return self::fields($row, ['price']);
    }
}

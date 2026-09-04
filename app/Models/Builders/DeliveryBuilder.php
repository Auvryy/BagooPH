<?php

namespace App\Models\Builders;

use App\Enums\DeliveryStatus;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;

class DeliveryBuilder extends Builder
{
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $colName = is_string($column) ? strtolower(trim($column)) : '';
        if (in_array($colName, ['status', 'deliveries.status'], true) && ($operator === '=' || $operator === '==')) {
            $equivalents = DeliveryStatus::getEquivalentStatuses($value);
            if (count($equivalents) > 1) {
                return $this->whereIn($column, $equivalents, $boolean);
            }
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $colName = is_string($column) ? strtolower(trim($column)) : '';
        if (in_array($colName, ['status', 'deliveries.status'], true) && ! $not) {
            if ($values instanceof Arrayable) {
                $values = $values->toArray();
            }
            $expanded = [];
            foreach ($values as $val) {
                $expanded = array_merge($expanded, DeliveryStatus::getEquivalentStatuses($val));
            }
            $values = array_values(array_unique($expanded));
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }
}

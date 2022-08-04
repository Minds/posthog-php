<?php

namespace PostHog;

const LONG_SCALE = 0xfffffffffffffff;

class FeatureFlag
{
    static function matchProperty($property, $propertyValues)
    {
        
        $key = $property["key"];
        $operator = $property["operator"] ?? "exact";
        $value = $property["value"];
    
        if (!array_key_exists($key, $propertyValues)) {
            throw new InconclusiveMatchException("Can't match properties without a given property value");
        }
    
    
        if ($operator == "is_not_set") {
            throw new InconclusiveMatchException("can't match properties with operator is_not_set");
        }
    
        $overrideValue = $propertyValues[$key];
        
        if ($operator == "exact") {
            if (is_array($value)) {
                return in_array($overrideValue, $value);
            }
            return $value == $overrideValue;
        }
    
        if ($operator == "is_not") {
            if (is_array($value)) {
                return !in_array($overrideValue, $value);
            }
            return $value !== $overrideValue;
        }
    
        if ($operator == "is_set") {
            return array_key_exists($key, $propertyValues);
        }
    
        if ($operator == "icontains") {
            return strpos(strtolower(strval($overrideValue)), strtolower(strval($value))) !== false;
        }
    
        if ($operator == "not_icontains") {
            return strpos(strtolower(strval($overrideValue)), strtolower(strval($value))) == false;
        }
    
        if ($operator == "regex") {
            return preg_match($value, $overrideValue);
        }
    
        if ($operator == "not_regex") {
            return !preg_match($value, $overrideValue);
        }
    
        if ($operator == "gt") {
            return gettype($value) == gettype($overrideValue) && $overrideValue > $value;
        }
    
        if ($operator == "gte") {
            return gettype($value) == gettype($overrideValue) && $overrideValue >= $value;
        }
    
        if ($operator == "lt") {
            return gettype($value) == gettype($overrideValue) && $overrideValue < $value;
        }
    
        if ($operator == "lte") {
            return gettype($value) == gettype($overrideValue) && $overrideValue <= $value;
        }
    
        return false;
    }

    static function _hash($key, $distinctId, $salt = "")
    {
        $hashKey = sprintf("%s.%s%s", $key, $distinctId, $salt);
        $hashVal = base_convert(substr(sha1(utf8_encode($hashKey)), 0, 15), 16, 10);

        return $hashVal / LONG_SCALE;
    }

    static function getMatchingVariant($flag, $distinctId)
    {
        $variants = FeatureFlag::variantLookupTable($flag);

        foreach ($variants as $variant) {
            if (
                FeatureFlag::_hash($flag["key"], $distinctId, "variant") >= $variant["value_min"]
                && FeatureFlag::_hash($flag["key"], $distinctId, "variant") < $variant["value_max"]
            ) {
                return $variant["key"];
            }
        }

        return null;
    }

    static function variantLookupTable($featureFlag)
    {
        $lookupTable = [];
        $valueMin = 0;
        $multivariates = (($featureFlag['filters'] ?? [])['multivariate'] ?? [])['variants'] ?? [];
        
        foreach ($multivariates as $variant) {
            $valueMax = $valueMin + $variant["rollout_percentage"] / 100;

            array_push($lookup_table, [
                "value_min" => $valueMin,
                "value_max" => $valueMax,
                "key" => $variant["key"]
            ]);
            $valueMin = $valueMax;
        }

        return $lookupTable;
    }

    static function matchFeatureFlagProperties($flag, $distinctId, $properties)
    {
        $flagConditions = ($flag["filters"] ?? [])["groups"] ?? [];
        $isInconclusive = false;

        foreach ($flagConditions as $condition) {
            try {
                if (FeatureFlag::isConditionMatch($flag, $distinctId, $condition, $properties)) {
                    return FeatureFlag::getMatchingVariant($flag, $distinctId) ?? true;
                }
            } catch (InconclusiveMatchException $e) {
                $isInconclusive = true;
            }
        }

        if ($isInconclusive) {
            throw new InconclusiveMatchException("Can't determine if feature flag is enabled or not with given properties");
        }

        return false;
    }

    static function isConditionMatch($featureFlag, $distinctId, $condition, $properties)
    {
        $rolloutPercentage = $condition["rollout_percentage"];

        if (count($condition['properties'] ?? []) > 0) {
            foreach ($condition['properties'] as $property) {
                if (!FeatureFlag::matchProperty($property, $properties)) {
                    return false;
                }
            }
                
            if (is_null($rolloutPercentage)) {
                return true;
            }
        }

        if (!is_null($rolloutPercentage) && FeatureFlag::_hash($featureFlag["key"], $distinctId) > ($rolloutPercentage / 100)) {
            return false;
        }

        return true;
    }

}

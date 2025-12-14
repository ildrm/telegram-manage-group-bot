<?php
namespace App\Services;

class RuleEvaluator {
    public function evaluate(array $rule, array $context): bool {
        $condition = $rule['condition'];
        // Simple condition parser: "field operator value"
        // Example: "message.text contains 'badword'"
        // Example: "user.reputation < 50"
        
        // This is a very basic implementation. In production, use ExpressionLanguage.
        
        foreach ($condition as $key => $expectedValue) {
            $actualValue = $this->getValueFromContext($key, $context);
            
            if ($actualValue != $expectedValue) { // Weak comparison for simplicity
                return false;
            }
        }
        
        return true;
    }
    
    private function getValueFromContext(string $key, array $context) {
        $parts = explode('.', $key);
        $value = $context;
        
        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } else {
                return null;
            }
        }
        
        return $value;
    }
}

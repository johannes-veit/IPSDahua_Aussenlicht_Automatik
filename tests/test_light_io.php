<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../AussenlichtAutomatik2/module.php');
$formRaw = file_get_contents(__DIR__ . '/../AussenlichtAutomatik2/form.json');
$form = $formRaw === false ? null : json_decode($formRaw, true);
if ($source === false || !is_array($form)) {
    fwrite(STDERR, "FAIL: Quelldateien nicht lesbar\n");
    exit(1);
}

$findByName = static function (array $items, string $name) use (&$findByName): ?array {
    foreach ($items as $item) {
        if (($item['name'] ?? null) === $name) {
            return $item;
        }
        foreach (['items', 'elements'] as $childKey) {
            if (isset($item[$childKey]) && is_array($item[$childKey])) {
                $found = $findByName($item[$childKey], $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }
    }
    return null;
};

$command = $findByName($form['elements'] ?? [], 'LightCommandVariableID');
$feedback = $findByName($form['elements'] ?? [], 'LightFeedbackVariableID');
if ($command === null || ($command['validVariableTypes'] ?? null) !== [0] || (int) ($command['requiredAction'] ?? 0) !== 1) {
    fwrite(STDERR, "FAIL: Schaltvariable muss Boolean mit Aktion sein\n");
    exit(1);
}
if ($feedback === null || ($feedback['validVariableTypes'] ?? null) !== [1, 2]) {
    fwrite(STDERR, "FAIL: Rückmeldung muss Integer/Float akzeptieren\n");
    exit(1);
}

$checks = [
    'numeric feedback validator' => 'private function isNumericVariable',
    'feedback 0/off >0/on' => 'return ((float) GetValue($varID)) > 0.0;',
    'action validation' => 'HasAction($commandVar)',
    'request action' => 'RequestAction($commandVar, $on)'
];
foreach ($checks as $label => $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: {$label} fehlt\n");
        exit(1);
    }
}

echo "OK: Licht-Schaltvariable Boolean/Aktion und Intensity-Rückmeldung geprüft\n";

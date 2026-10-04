<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../AussenlichtAutomatik2/module.php');
$form = file_get_contents(__DIR__ . '/../AussenlichtAutomatik2/form.json');
if ($source === false || $form === false) {
    fwrite(STDERR, "FAIL: Quelldateien nicht lesbar\n");
    exit(1);
}

$requiredSource = [
    "RegisterPropertyBoolean('LightAutomationEnabled', true)",
    "RegisterPropertyString('CameraHost', '')",
    "RegisterPropertyInteger('LightCommandVariableID', 0)",
    "RegisterPropertyInteger('LightFeedbackVariableID', 0)",
    "if (!\$this->lightAutomationEnabled())",
    "IPS_SetHidden(\$nightID, !\$this->lightAutomationEnabled())"
];

foreach ($requiredSource as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: Flexible-Modus-Code fehlt: {$needle}\n");
        exit(1);
    }
}

$requiredForm = [
    '"name": "LightAutomationEnabled"',
    'reine Personenerkennung',
    'Pro Dahua-Kamera eine eigene Instanz'
];
foreach ($requiredForm as $needle) {
    if (strpos($form, $needle) === false) {
        fwrite(STDERR, "FAIL: Formularhinweis fehlt: {$needle}\n");
        exit(1);
    }
}

echo "OK: flexible Kamera-/Lichtbetriebsart statisch geprüft\n";

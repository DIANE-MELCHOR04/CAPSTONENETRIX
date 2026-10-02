<?php
$appCssVersion = (string) filemtime(__DIR__ . '/../assets/css/app.css');
$designTokensVersion = (string) filemtime(__DIR__ . '/../assets/css/design-tokens.css');
$operationsShellCssVersion = (string) filemtime(__DIR__ . '/../assets/css/operations-shell.css');
$operationsComponentsVersion = (string) filemtime(__DIR__ . '/../assets/css/operations-components.css');
$operationsResponsiveVersion = (string) filemtime(__DIR__ . '/../assets/css/operations-responsive.css');
$system1ThemeVersion = (string) filemtime(__DIR__ . '/../assets/css/system1-theme.css');
$appJsVersion = (string) filemtime(__DIR__ . '/../assets/js/app.js');
$operationsShellJsVersion = (string) filemtime(__DIR__ . '/../assets/js/operations-shell.js');
?>
<link rel="icon" type="image/png" href="assets/logo.png">
<link rel="stylesheet" href="assets/vendor/notiflix/notiflix-3.2.8.min.css?v=3.2.8">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $appCssVersion ?>">
<link rel="stylesheet" href="assets/css/design-tokens.css?v=<?= $designTokensVersion ?>">
<link rel="stylesheet" href="assets/css/operations-shell.css?v=<?= $operationsShellCssVersion ?>">
<link rel="stylesheet" href="assets/css/operations-components.css?v=<?= $operationsComponentsVersion ?>">
<link rel="stylesheet" href="assets/css/operations-responsive.css?v=<?= $operationsResponsiveVersion ?>">
<link rel="stylesheet" href="assets/css/system1-theme.css?v=<?= $system1ThemeVersion ?>">
<script src="assets/vendor/notiflix/notiflix-3.2.8.min.js?v=3.2.8" defer></script>
<script src="assets/js/app.js?v=<?= $appJsVersion ?>" defer></script>
<script src="assets/js/operations-shell.js?v=<?= $operationsShellJsVersion ?>" defer></script>

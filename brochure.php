<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

if (!can_tour()) {
    header('Location: ' . base_url() . '?falta=registro');
    exit;
}
if (!brochure_exists()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('El brochure todavía no está disponible.');
}
session_write_close();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Brochure-Petersen-Industria.pdf"');
header('Content-Length: ' . filesize(BROCHURE_PATH));
readfile(BROCHURE_PATH);

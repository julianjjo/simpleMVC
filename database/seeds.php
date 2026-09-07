<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Datos de ejemplo
|--------------------------------------------------------------------------
|
| Se aplican con `php bin/console.php seed`. Devuelve un closure para que el
| comando pueda reutilizarlo en los tests.
|
 */

use SimpleMvc\Core\Database;

return function (Database $db, bool $force = false): int {
    $already = (int) $db->scalar('SELECT COUNT(*) FROM productos');

    if ($already > 0) {
        if (!$force) {
            return 0;
        }

        $db->statement('DELETE FROM productos');
    }

    $rows = [
        ['teclado mecanico inalambrico k80', 'Teclado mecánico inalámbrico K80', 'perifericos', 189900.0, 24, 1, 'Switches táctiles, retroiluminación RGB por tecla y batería de 40 horas. Compatible con Windows, Linux y macOS.'],
        ['mouse ergonomico vertical mx-anywhere', 'Mouse ergonómico vertical MX-Anywhere', 'perifericos', 94500.0, 41, 0, 'Rotación de 57° para descargar la muñeca, sensor de 4000 DPI y seis botones programables.'],
        ['monitor ultrawide 34 pulgadas curvo', 'Monitor ultrawide 34" curvo', 'monitores', 1250000.0, 7, 1, 'Panel IPS curva 1500R, 3440×1440 a 144 Hz, USB-C con 90 W de carga y Hub integrado.'],
        ['monitor 27 pulgadas 4k ips', 'Monitor 27" 4K IPS', 'monitores', 980000.0, 12, 0, '3840×2160, 98 % DCI-P3, calibrado de fábrica para diseño y edición de fotografía.'],
        ['audifonos over-ear con cancelacion de ruido', 'Audífonos over-ear con cancelación de ruido', 'audio', 425000.0, 33, 1, 'ANC híbrida, 38 horas de autonomía y dos emisores Bluetooth para cambiar de dispositivo sin cables.'],
        ['barra de sonido compacta', 'Barra de sonido compacta', 'audio', 310000.0, 18, 0, '2.1 canales con subwoofer inalámbrico, HDMI eARC y modo noche para no despertar a nadie.'],
        ['parlante bluetooth portatil ipx7', 'Parlante Bluetooth portátil IPX7', 'audio', 128900.0, 55, 0, 'Resistencia al agua IPX7, 20 horas de reproducción y emparejamiento estéreo con un segundo parlante.'],
        ['ssd nvme gen4 1tb', 'SSD NVMe Gen4 1 TB', 'almacenamiento', 289000.0, 64, 1, 'Lectura de 7000 MB/s y escritura de 5300 MB/s; disipador de bajo perfil incluido.'],
        ['nas de dos bahias', 'NAS de dos bahías', 'almacenamiento', 1150000.0, 4, 0, 'RAID 1 local con copia cifrada a la nube y acceso remoto sin abrir puertos.'],
        ['placa madre micro-atx', 'Placa madre micro-ATX', 'componentes', 655000.0, 9, 0, 'Socket AM5, VRM de 12 fases, dos ranuras M.2 y 2.5 GbE.'],
        ['fuente 750w 80 plus gold', 'Fuente 750 W 80 Plus Gold', 'componentes', 274000.0, 21, 0, 'Totalmente modular, ventilador de 120 mm con modo semipasivo y garantía de diez años.'],
        ['tarjeta de video 8 gb', 'Tarjeta de video 8 GB', 'componentes', 1890000.0, 3, 1, 'Doble ventilador, DLSS y codificador AV1 para streaming sin cargar la CPU.'],
        ['webcam 1080p con autofoco', 'Webcam 1080p con autofoco', 'perifericos', 152000.0, 0, 0, 'Sensor de 1/2.8", corrección automática de luz y tapa física para el lente.'],
        ['microusb hub con ethernet', 'Hub USB-C con Ethernet', 'perifericos', 89900.0, 27, 0, 'Puertos HDMI 4K30, USB-A 3.2, lector SD y Ethernet gigabit en un chasis de aluminio.'],
    ];

    $inserted = 0;

    foreach ($rows as [$slug, $nombre, $categoria, $precio, $stock, $destacado, $descripcion]) {
        $db->table('productos')->insert([
            'nombre' => $nombre,
            'slug' => \SimpleMvc\Support\Str::slug($slug),
            'descripcion' => $descripcion,
            'precio' => $precio,
            'stock' => $stock,
            'categoria' => $categoria,
            'destacado' => $destacado,
            'creado_en' => date('Y-m-d H:i:s', strtotime('-'.(7 + $inserted * 3).' days')),
        ]);

        ++$inserted;
    }

    return $inserted;
};

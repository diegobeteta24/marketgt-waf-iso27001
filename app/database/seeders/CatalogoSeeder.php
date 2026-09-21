<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Catálogo de demostración de MarketGT: seis categorías y treinta y un productos de una
 * tienda guatemalteca.
 *
 * Se usa updateOrCreate contra el slug para que el sembrado pueda repetirse durante los
 * ensayos de la presentación sin duplicar filas ni romper los índices únicos.
 */
class CatalogoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ($this->catalogo() as $orden => $datos) {
            $categoria = Categoria::updateOrCreate(
                ['slug' => Str::slug($datos['nombre'])],
                [
                    'nombre' => $datos['nombre'],
                    'descripcion' => $datos['descripcion'],
                    'orden' => $orden + 1,
                    'activa' => true,
                ],
            );

            foreach ($datos['productos'] as $producto) {
                Producto::updateOrCreate(
                    ['slug' => Str::slug($producto['nombre'])],
                    [
                        'categoria_id' => $categoria->id,
                        'nombre' => $producto['nombre'],
                        'descripcion' => $producto['descripcion'],
                        'precio' => $producto['precio'],
                        'existencias' => $producto['existencias'],
                        'imagen_url' => null,
                        'activo' => true,
                    ],
                );
            }
        }
    }

    /**
     * Precios en quetzales tomados de rangos reales del mercado guatemalteco. Las marcas
     * son de fantasía: no se usan nombres de empresas ni de personas reales.
     *
     * @return array<int, array{nombre: string, descripcion: string, productos: array<int, array{nombre: string, descripcion: string, precio: string, existencias: int}>}>
     */
    private function catalogo(): array
    {
        return [
            [
                'nombre' => 'Café y Cacao',
                'descripcion' => 'Granos de altura y cacao de las tierras altas, tostados en pequeños lotes.',
                'productos' => [
                    [
                        'nombre' => 'Café de Antigua tostado medio 500 g',
                        'descripcion' => 'Grano arábigo cultivado a 1,500 metros sobre el nivel del mar en las faldas del volcán de Agua. Cuerpo medio, acidez cítrica y notas de chocolate con leche. Disponible en grano entero o molido a pedido.',
                        'precio' => '78.00',
                        'existencias' => 64,
                    ],
                    [
                        'nombre' => 'Café de Huehuetenango en grano 1 kg',
                        'descripcion' => 'Cosecha de altura de la sierra de los Cuchumatanes. Perfil frutal intenso con final a panela. Empaque con válvula desgasificadora que conserva el aroma hasta ocho semanas.',
                        'precio' => '145.00',
                        'existencias' => 38,
                    ],
                    [
                        'nombre' => 'Café de Cobán tostado oscuro 500 g',
                        'descripcion' => 'Tueste profundo de las fincas nubosas de Alta Verapaz. Amargor equilibrado, ideal para espresso y para café de olla. Bolsa resellable.',
                        'precio' => '72.50',
                        'existencias' => 52,
                    ],
                    [
                        'nombre' => 'Tablilla de cacao artesanal caja de 8',
                        'descripcion' => 'Cacao molido en piedra con canela y un toque de achiote, moldeado a mano en tablillas de 45 gramos. Rinde ocho tazas de chocolate caliente espeso.',
                        'precio' => '45.00',
                        'existencias' => 90,
                    ],
                    [
                        'nombre' => 'Cacao en polvo puro de Alta Verapaz 250 g',
                        'descripcion' => 'Cacao sin azúcar añadida, prensado en frío y tamizado fino. Para repostería, batidos y bebidas ceremoniales.',
                        'precio' => '58.00',
                        'existencias' => 47,
                    ],
                ],
            ],
            [
                'nombre' => 'Textiles Típicos',
                'descripcion' => 'Telar de cintura y telar de pie tejidos por cooperativas del altiplano.',
                'productos' => [
                    [
                        'nombre' => 'Huipil bordado a mano de San Antonio Aguas Calientes',
                        'descripcion' => 'Pieza única bordada durante casi tres meses en técnica de doble cara. Algodón teñido con tintes naturales, con figuras de aves y flores sobre fondo rojo profundo. Talla única.',
                        'precio' => '850.00',
                        'existencias' => 7,
                    ],
                    [
                        'nombre' => 'Corte típico de telar de cintura',
                        'descripcion' => 'Corte de siete varas tejido en telar de cintura con jaspe azul índigo. Tela gruesa que cae con peso y se ablanda con el uso.',
                        'precio' => '620.00',
                        'existencias' => 11,
                    ],
                    [
                        'nombre' => 'Faja tejida de Nahualá',
                        'descripcion' => 'Faja de dos metros y medio con grecas geométricas en rojo, verde y amarillo. Se usa ceñida sobre el corte o como cinturón ancho.',
                        'precio' => '185.00',
                        'existencias' => 24,
                    ],
                    [
                        'nombre' => 'Camino de mesa de Momostenango 2 m',
                        'descripcion' => 'Lana virgen hilada y teñida en Momostenango, tejida en telar de pie. Bordes rematados a mano. Dos metros por cuarenta centímetros.',
                        'precio' => '240.00',
                        'existencias' => 19,
                    ],
                    [
                        'nombre' => 'Morral de telar multicolor',
                        'descripcion' => 'Morral de hombro con forro interior de manta y correa ajustable. Combina tres cortes distintos, por lo que no hay dos iguales.',
                        'precio' => '135.00',
                        'existencias' => 43,
                    ],
                ],
            ],
            [
                'nombre' => 'Jade y Joyería',
                'descripcion' => 'Jadeíta del valle del Motagua tallada y montada en plata 925.',
                'productos' => [
                    [
                        'nombre' => 'Dije de jade verde imperial con cadena de plata',
                        'descripcion' => 'Jadeíta traslúcida de tono verde imperial, tallada y pulida a mano, montada en engaste de plata 925 con cadena de cincuenta centímetros. Incluye certificado de origen.',
                        'precio' => '1250.00',
                        'existencias' => 9,
                    ],
                    [
                        'nombre' => 'Aretes de jade lila talla redonda',
                        'descripcion' => 'Par de cabujones de jade lila de diez milímetros sobre poste de plata 925 con cierre de mariposa. El tono lila es de los menos frecuentes del yacimiento.',
                        'precio' => '480.00',
                        'existencias' => 16,
                    ],
                    [
                        'nombre' => 'Pulsera de plata 925 con jade',
                        'descripcion' => 'Siete placas de jade verde engarzadas en eslabones de plata maciza. Broche de seguridad doble. Diecinueve centímetros de largo.',
                        'precio' => '690.00',
                        'existencias' => 12,
                    ],
                    [
                        'nombre' => 'Figura de jade tallada ave quetzal',
                        'descripcion' => 'Talla de doce centímetros trabajada en una sola pieza de jade verde oscuro, con base de madera de conacaste. Tres semanas de trabajo de taller.',
                        'precio' => '1890.00',
                        'existencias' => 4,
                    ],
                    [
                        'nombre' => 'Anillo de plata con jade negro',
                        'descripcion' => 'Jade negro guatemalteco de corte rectangular sobre banda ancha de plata 925 con acabado satinado. Tallas de la 6 a la 10.',
                        'precio' => '575.00',
                        'existencias' => 14,
                    ],
                ],
            ],
            [
                'nombre' => 'Hogar y Cocina',
                'descripcion' => 'Barro, vidrio soplado y hierro para la cocina de todos los días.',
                'productos' => [
                    [
                        'nombre' => 'Comal de barro de Chinautla 35 cm',
                        'descripcion' => 'Barro trabajado a mano sin torno y cocido en horno de leña. Treinta y cinco centímetros de diámetro. Debe curarse con cal antes del primer uso.',
                        'precio' => '95.00',
                        'existencias' => 33,
                    ],
                    [
                        'nombre' => 'Juego de 6 vasos de vidrio soplado de Cantel',
                        'descripcion' => 'Vidrio reciclado soplado a boca, con el borde grueso y las burbujas características del oficio. Trescientos mililitros cada vaso. Aptos para lavavajillas.',
                        'precio' => '210.00',
                        'existencias' => 27,
                    ],
                    [
                        'nombre' => 'Molino manual de maíz de hierro fundido',
                        'descripcion' => 'Molino de tornillo con muelas templadas y mordaza para mesa de hasta cinco centímetros de grosor. Muele maíz nixtamalizado, café y granos secos.',
                        'precio' => '365.00',
                        'existencias' => 15,
                    ],
                    [
                        'nombre' => 'Olla de barro vidriado 5 L',
                        'descripcion' => 'Olla de cinco litros con vidriado interior libre de plomo, apta para fuego directo y horno. Tapadera incluida. Guarda el calor mucho después de apagar la hornilla.',
                        'precio' => '168.00',
                        'existencias' => 21,
                    ],
                    [
                        'nombre' => 'Tortillero de tule tejido',
                        'descripcion' => 'Canasta de tule tejida a mano con tapadera y forro de manta de algodón desmontable. Mantiene las tortillas calientes cerca de cuarenta minutos.',
                        'precio' => '62.00',
                        'existencias' => 58,
                    ],
                ],
            ],
            [
                'nombre' => 'Tecnología',
                'descripcion' => 'Accesorios de escritorio y movilidad con garantía local de doce meses.',
                'productos' => [
                    [
                        'nombre' => 'Audífonos inalámbricos Kaqchi Pulse',
                        'descripcion' => 'Audífonos de botón con cancelación activa de ruido, seis horas de reproducción continua y veinticuatro horas adicionales en el estuche de carga. Resistencia al sudor IPX5.',
                        'precio' => '425.00',
                        'existencias' => 40,
                    ],
                    [
                        'nombre' => 'Teclado mecánico compacto Xelajú K68',
                        'descripcion' => 'Distribución en español latinoamericano al sesenta y cinco por ciento, interruptores rojos lineales intercambiables en caliente y conexión por cable o Bluetooth a tres equipos.',
                        'precio' => '560.00',
                        'existencias' => 22,
                    ],
                    [
                        'nombre' => 'Mouse ergonómico inalámbrico Atitlán M2',
                        'descripcion' => 'Diseño vertical a cincuenta y siete grados que reduce la tensión de la muñeca. Sensor de 4,000 DPI ajustable en cuatro pasos y receptor de 2.4 GHz.',
                        'precio' => '189.00',
                        'existencias' => 55,
                    ],
                    [
                        'nombre' => 'Batería portátil 20,000 mAh Volcán Power',
                        'descripcion' => 'Carga rápida de 22.5 W por USB-C con entrega de energía bidireccional. Pantalla de porcentaje y protección contra sobrecarga y cortocircuito.',
                        'precio' => '315.00',
                        'existencias' => 36,
                    ],
                    [
                        'nombre' => 'Cámara web 1080p Izabal View',
                        'descripcion' => 'Sensor de dos megapíxeles a treinta cuadros por segundo con corrección automática de luz, micrófono estéreo con reducción de ruido y tapa de privacidad física.',
                        'precio' => '275.00',
                        'existencias' => 29,
                    ],
                    [
                        'nombre' => 'Concentrador USB-C de 7 puertos Petén Hub',
                        'descripcion' => 'HDMI 4K a treinta hercios, tres puertos USB 3.0, lector de tarjetas SD y microSD, red gigabit y paso de carga de 100 W. Carcasa de aluminio.',
                        'precio' => '240.00',
                        'existencias' => 31,
                    ],
                ],
            ],
            [
                'nombre' => 'Ropa y Calzado',
                'descripcion' => 'Cuero, mezclilla y lino trabajados en talleres de Quetzaltenango y Antigua.',
                'productos' => [
                    [
                        'nombre' => 'Sandalias de cuero artesanal Tikal',
                        'descripcion' => 'Cuero de res curtido vegetal cosido a mano sobre suela de caucho reciclado. Tallas de la 36 a la 44. El cuero se amolda al pie en la primera semana.',
                        'precio' => '395.00',
                        'existencias' => 26,
                    ],
                    [
                        'nombre' => 'Chumpa de mezclilla Xela Denim',
                        'descripcion' => 'Mezclilla de trece onzas con forro de franela en el cuerpo, botones metálicos y dos bolsas interiores. Corte recto, tallas de la S a la XL.',
                        'precio' => '450.00',
                        'existencias' => 18,
                    ],
                    [
                        'nombre' => 'Camisa de lino bordada Antigua',
                        'descripcion' => 'Lino de peso ligero con bordado de grecas en el canesú, hecho a mano por artesanas de Sacatepéquez. Manga larga con puño abotonado.',
                        'precio' => '320.00',
                        'existencias' => 23,
                    ],
                    [
                        'nombre' => 'Botas de cuero montaña Tajumulco',
                        'descripcion' => 'Bota de caña media en cuero engrasado, con suela de tacos profundos y costura reforzada en la puntera. Pensada para terreno volcánico húmedo.',
                        'precio' => '780.00',
                        'existencias' => 0,
                    ],
                    [
                        'nombre' => 'Sombrero de palma tejido a mano',
                        'descripcion' => 'Palma de Rabinal tejida en trama cerrada, con cinta interior de algodón y ala de siete centímetros. Se puede enrollar sin perder la forma.',
                        'precio' => '145.00',
                        'existencias' => 61,
                    ],
                ],
            ],
        ];
    }
}

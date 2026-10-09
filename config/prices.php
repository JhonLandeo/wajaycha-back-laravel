<?php

declare(strict_types=1);

/**
 * Price sources, their windows and the curated slug mapping (grocery-prices
 * design D3, D6, D12, D13, D14).
 *
 * This is data, following the `config/shopping.php` precedent: moving a
 * staleness window or retiring a product from a source is an edit here, not
 * a change to control flow.
 *
 * Source keys are the identity of the store/provider and are used everywhere
 * (rows, commands, purge): `plazavea`, `inei`, `emmsa`, `gmml`. There is no
 * separate `store` field — a future `metro` is one more key.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Outbound identity
    |--------------------------------------------------------------------------
    |
    | Every source request identifies itself. Set PRICES_USER_AGENT in the
    | environment to include a real contact; the fallback only names the app.
    |
    */

    'user_agent' => env('PRICES_USER_AGENT', 'WajaychaPriceBot/0.1 (personal finance app)'),

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | enabled          Kill switch. Honoured on fetch (command and again inside
    |                  every job) AND on read. Plaza Vea defaults to OFF until the
    |                  owner has read the store's Terms and Conditions (ADR-0010).
    | kind             retail | wholesale. Only retail can price a line.
    | rank             Lower wins among sources of the same freshness.
    | fresh_days       Age (days since period_end) up to which a quote is fresh.
    | stale_days       Age up to which it is still usable as stale; older = expired.
    | attribution      Template; {dd/mm}, {mmm} and {yyyy} come from period_end.
    | canary_min_rows  rows_written below this trips the freshness canary.
    |
    */

    'sources' => [

        'plazavea' => [
            'enabled' => (bool) env('PRICES_PLAZAVEA_ENABLED', false),
            'kind' => 'retail',
            'label' => 'Plaza Vea',
            'rank' => 1,
            'fresh_days' => 8,
            'stale_days' => 21,
            'attribution' => 'Precio online de Plaza Vea al {dd/mm}',
            'canary_min_rows' => 28,
            // Seconds between consecutive product jobs; must stay >= 5 (spec).
            'spacing_seconds' => 6,
        ],

        'inei' => [
            'enabled' => (bool) env('PRICES_INEI_ENABLED', true),
            'kind' => 'retail',
            'label' => 'INEI',
            'rank' => 2,
            'fresh_days' => 75,
            'stale_days' => 135,
            'attribution' => 'Promedio Lima INEI, {mmm} {yyyy}',
            'canary_min_rows' => 25,
            // |latest / previous month - 1| above this quarantines the row.
            'max_mom_change' => 0.6,
            // The monthly bulletin collection on gob.pe; editions are links on it.
            'base_url' => 'https://www.gob.pe',
            'collection_path' => '/institucion/inei/colecciones/6630-indicadores-de-precios-de-la-economia',
        ],

        'emmsa' => [
            'enabled' => (bool) env('PRICES_EMMSA_ENABLED', true),
            'kind' => 'wholesale',
            'label' => 'EMMSA',
            'rank' => 10,
            'fresh_days' => 4,
            'stale_days' => 4,
            'attribution' => null,
            'canary_min_rows' => 8,
            // The old report endpoint: POST form, answers an HTML table.
            'url' => 'https://old.emmsa.com.pe/emmsa_spv/app/reportes/ajax/rpt07_gettable_new_web.php',
        ],

        'gmml' => [
            'enabled' => (bool) env('PRICES_GMML_ENABLED', true),
            'kind' => 'wholesale',
            'label' => 'GMML',
            'rank' => 11,
            'fresh_days' => 4,
            'stale_days' => 4,
            'attribution' => null,
            'canary_min_rows' => 5,
            // The daily bulletin collection on gob.pe; month pages link one PDF per business day.
            'base_url' => 'https://www.gob.pe',
            'collection_path' => '/institucion/midagri/colecciones/335-reporte-de-ingreso-y-precios-en-el-gran-mercado-mayorista-de-lima',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Wholesale trend
    |--------------------------------------------------------------------------
    */

    'trend' => [
        // The latest wholesale point must be at most this old.
        'max_age_days' => 4,
        // The comparison point is taken about a week earlier, inside this window.
        'window_min_days' => 5,
        'window_max_days' => 10,
        // |change| below this percentage reads as flat.
        'flat_epsilon_pct' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Curated mapping: catalogue slug -> source-specific terms
    |--------------------------------------------------------------------------
    |
    | Never fuzzy. A slug without an entry for a source gets no quote from it.
    | Written from the S0 spike (Plaza Vea 2026-10-08, INEI Cuadro 19 August
    | 2026, EMMSA 07/10/2026, GMML 07/10/2026). Three catalogue slugs are
    | deliberately absent from Plaza Vea because the spike found nothing usable:
    | perejil, rocoto and aji-amarillo.
    |
    | kg_per_unit     Curated "one unit weighs this many kg", used ONLY inside the
    |                 price normalizer when a count product is priced by weight.
    |                 It makes the quote `basis = equivalence`. These are guesses
    |                 the owner should review.
    | plazavea        category_path is VTEX's full path; patterns are PCRE with
    |                 delimiters. ALL must_match patterns must hit, NONE of the
    |                 must_not_match patterns may. assume_single prices any pack
    |                 as one unit (a can, a bunch).
    | inei            Exact label and unit as printed in Cuadro 19.
    | emmsa           prod = request code; product/variety_match select rows by
    |                 NAME in the response (rows carry no codes). Kg products only.
    | gmml            Product name as printed in the daily bulletin.
    |
    */

    'mapping' => [
        'papa-blanca' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/835/',
                'term' => 'papa blanca',
                'must_match' => ['/blanca/iu'],
                'must_not_match' => ['/seca|frita|prefrita|chips|snack|nativa|congelad|pur[eé]|amarilla|huayro|camote|yuca|sancochad|ensalada|rellena|crema|salsa/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'PAPA BLANCA', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '38', 'product' => 'PAPA', 'variety_match' => '/^PAPA BLANCA/iu'],
            'gmml' => ['label' => 'Papa Blanca'],
        ],
        'papa-amarilla' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/835/',
                'term' => 'papa amarilla',
                'must_match' => ['/amarilla/iu'],
                'must_not_match' => ['/seca|frita|chips|snack|congelad|pur[eé]|sancochad|crema|salsa|aj[ií]|pasta/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'PAPA AMARILLA', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '38', 'product' => 'PAPA', 'variety_match' => '/^PAPA AMARILLA/iu'],
            'gmml' => ['label' => 'Papa Amarilla'],
        ],
        'camote' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/835/',
                'term' => 'camote',
                'must_match' => ['/camote/iu'],
                'must_not_match' => ['/chifle|frito|chips|snack|congelad|pur[eé]|sancochad/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'CAMOTE AMARILLO', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '12', 'product' => 'CAMOTE', 'variety_match' => '/AMARILLO/iu'],
            'gmml' => ['label' => 'Camote Amarillo'],
        ],
        'yuca' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/835/',
                'term' => 'yuca',
                'must_match' => ['/yuca/iu'],
                'must_not_match' => ['/chifle|frita|chips|snack|congelad|sancochad|harina/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'YUCA', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '52', 'product' => 'YUCA', 'variety_match' => '/^YUCA AMARILLA/iu'],
            'gmml' => ['label' => 'Yuca Amarilla'],
        ],
        'arroz' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/432/',
                'term' => 'arroz',
                'must_match' => ['/arroz/iu'],
                'must_not_match' => ['/olla|arrocera|integral|instant|sushi|basmati|jazm|rellen|con leche|chaufa|pre ?cocido|canasta|arborio|carnaroli|jap[oó]nic|sticky|risott|glaseado|glutinoso|pack/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'ARROZ A GRANEL CORRIENTE', 'unit' => 'KILOGRAMO'],
        ],
        'azucar' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/434/',
                'term' => 'azúcar',
                'must_match' => ['/az[uú]car/iu'],
                'must_not_match' => ['/impalpable|polvo|stevia|splenda|edulcorante|panela|sobre|sachet|light|estuche|cubo|coco|sticks?/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'AZÚCAR RUBIA', 'unit' => 'KILOGRAMO'],
        ],
        'sal' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/524/525/',
                'term' => 'sal',
                // Table salt sold in a bag. Shakers of 125 g inflate the per-kg
                // median (S/ 43/kg against S/ 2/kg for the 1 kg bag), so the bag
                // is part of the match rather than a size filter.
                'must_match' => ['/sal (de cocina|de mesa|marina|yodada)/iu', '/bolsa/iu'],
                'must_not_match' => ['/pimienta|himalaya|rosada|maras|parrill|biosal|sodio|saborizante|molinillo|gourmet|sobre|light/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'SAL YODADA DE COCINA', 'unit' => 'KILOGRAMO'],
        ],
        'aceite-vegetal' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/433/600/',
                'term' => 'aceite vegetal',
                'must_match' => ['/aceite/iu'],
                'must_not_match' => ['/oliva|motor|spray|pack|combo/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'ACEITE VEGETAL (BOTELLA)', 'unit' => 'LITRO'],
        ],
        'fideos' => [
            'kg_per_unit' => 0.5,
            'plazavea' => [
                'category_path' => '/431/436/',
                'term' => 'fideos',
                'must_match' => ['/fideo|spaghetti|espagueti|tallar/iu'],
                'must_not_match' => ['/chino|instant|sopa|ramen|integral|sin gluten|lasa|relleno|salsa|lata/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'FIDEOS PASTA LARGA', 'unit' => 'KILOGRAMO'],
        ],
        'lentejas' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/435/448/',
                'term' => 'lenteja',
                'must_match' => ['/lenteja/iu'],
                'must_not_match' => ['/conserva|lata|mezcla/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'LENTEJA', 'unit' => 'KILOGRAMO'],
        ],
        'frejol' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/435/446/',
                'term' => 'frijol',
                'must_match' => ['/fr[eé]?[ij]ol|canario|panamito|negro|castilla/iu'],
                'must_not_match' => ['/conserva|lata|mezcla|cocido/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'FRÉJOL CANARIO', 'unit' => 'KILOGRAMO'],
        ],
        'garbanzo' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/435/451/',
                'term' => 'garbanzo',
                'must_match' => ['/garbanzo/iu'],
                'must_not_match' => ['/conserva|lata|cocido|snack/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'GARBANZO', 'unit' => 'KILOGRAMO'],
        ],
        'pollo-entero' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/814/815/841/',
                'term' => 'pollo entero',
                'must_match' => ['/pollo/iu'],
                'must_not_match' => ['/rostizado|elaborado|nugget|apanado|sabor|le[nñ]a|redondos|marinad/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'POLLO EVISCERADO', 'unit' => 'KILOGRAMO'],
        ],
        'carne-de-res' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/814/820/1684/',
                'term' => 'carne res',
                'must_match' => ['/bistec|lomo|pulpa|carne|res/iu'],
                'must_not_match' => ['/molida|hamburguesa|elaborado|chorizo|menudencia|marinad|parrill/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'RES BISTEC', 'unit' => 'KILOGRAMO'],
        ],
        'huevos' => [
            'kg_per_unit' => 0.06,
            'plazavea' => [
                'category_path' => '/845/839/',
                'term' => 'huevos',
                'must_match' => ['/huevo/iu'],
                'must_not_match' => ['/codorniz|pascua|chocolate|liquido|l[ií]quido|cocido|duro|clara|yema/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'HUEVO A GRANEL', 'unit' => 'KILOGRAMO'],
        ],
        'leche-evaporada' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/845/846/853/',
                'term' => 'leche evaporada',
                'must_match' => ['/evaporada/iu'],
                'must_not_match' => ['/mezcla|l[aá]ctea|condensada|polvo|bebida|pack|ni[nñ]os/iu'],
                'assume_single' => true,
            ],
            'inei' => ['label' => 'LECHE EVAPORADA', 'unit' => 'LATA G,'],
        ],
        'queso-fresco' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/621/847/62/',
                'term' => 'queso fresco',
                'must_match' => ['/queso fresco|fresco/iu'],
                'must_not_match' => ['/light|untable|crema|rallado|parmesano/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'QUESO FRESCO DE VACA', 'unit' => 'KILOGRAMO'],
        ],
        'mantequilla' => [
            'kg_per_unit' => 0.2,
            'plazavea' => [
                'category_path' => '/845/857/1007/',
                'term' => 'mantequilla',
                'must_match' => ['/mantequilla/iu'],
                'must_not_match' => ['/man[ií]|almendra|ghee|sin sal.*light|pack/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'MANTEQUILLA ENVASADA', 'unit' => 'KILOGRAMO'],
        ],
        'pan' => [
            'kg_per_unit' => 0.08,
            'plazavea' => [
                'category_path' => '/493/531/538/',
                'term' => 'pan francés',
                'must_match' => ['/pan/iu'],
                'must_not_match' => ['/molde|pita|[aá]rabe|tostad|rallado|brioche|integral/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'PAN FRANCÉS', 'unit' => 'KILOGRAMO'],
        ],
        'cebolla-roja' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/827/',
                'term' => 'cebolla roja',
                'must_match' => ['/cebolla/iu'],
                'must_not_match' => ['/china|blanca|encurtid|crispy|polvo|frita|congelad|pack/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'CEBOLLA CABEZA', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '14', 'product' => 'CEBOLLA', 'variety_match' => '/^CEBOLLA CABEZA ROJA/iu'],
            'gmml' => ['label' => 'Cebolla Cabeza Roja'],
        ],
        'tomate' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/834/',
                'term' => 'tomate',
                'must_match' => ['/tomate/iu'],
                'must_not_match' => ['/salsa|pasta|conserva|pelado|cherry|deshidr|seco|ketchup|pur[eé]|pack/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'TOMATE ITALIANO', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '48', 'product' => 'TOMATE', 'variety_match' => '/^TOMATE (?!CHERRY|ORGANICO)/iu'],
            'gmml' => ['label' => 'Tomate'],
        ],
        'ajo' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/827/',
                'term' => 'ajo',
                'must_match' => ['/\bajo\b/iu'],
                'must_not_match' => ['/pasta|polvo|molido|frito|pur[eé]|tipo|aj[ií]|chino negro|mayo|encurtid|pelado/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'AJO ENTERO', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '03', 'product' => 'AJO', 'variety_match' => '/^AJO (CRIOLLO|CHINO)/iu'],
            'gmml' => ['label' => 'Ajo Criollo O Napuri'],
        ],
        'limon' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/1618/',
                'term' => 'limón',
                'must_match' => ['/lim[oó]n/iu'],
                'must_not_match' => ['/jugo|pack|concentrado|zumo|cera|sutil.*bolsa/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'LIMÓN', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '30', 'product' => 'LIMON', 'variety_match' => '/^LIMON CITRICO/iu'],
            'gmml' => ['label' => 'Limon Sutil Bolsa'],
        ],
        'palta' => [
            'kg_per_unit' => 0.2,
            'plazavea' => [
                'category_path' => '/77/817/986/',
                'term' => 'palta',
                'must_match' => ['/palta/iu'],
                'must_not_match' => ['/pur[eé]|guacamole|pack|pulpa|congelad|aceite/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'PALTA FUERTE', 'unit' => 'KILOGRAMO'],
        ],
        'platano' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/817/989/',
                'term' => 'plátano',
                'must_match' => ['/pl[aá]tano|banano|banana/iu'],
                'must_not_match' => ['/frei|freír|verde|bellaco|chifle|hojuela|pur[eé]|pack|seda.*pack|uva/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'PLÁTANO DE SEDA', 'unit' => 'KILOGRAMO'],
        ],
        'platano-de-freir' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/817/989/',
                'term' => 'plátano bellaco',
                'must_match' => ['/pl[aá]tano.*(verde|bellaco|frei|fre[ií]r|macho)|bellaco/iu'],
                'must_not_match' => ['/chifle|hojuela|pur[eé]|pack|congelad|frito/iu'],
                'assume_single' => false,
            ],
        ],
        'zanahoria' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/836/',
                'term' => 'zanahoria',
                'must_match' => ['/zanahoria/iu'],
                'must_not_match' => ['/pack|baby|rallad|picad|congelad|jugo/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'ZANAHORIA', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '53', 'product' => 'ZANAHORIA', 'variety_match' => '/^ZANAHORIA/iu'],
            'gmml' => ['label' => 'Zanahoria'],
        ],
        'choclo' => [
            'kg_per_unit' => 0.3,
            'plazavea' => [
                'category_path' => '/77/818/1619/',
                'term' => 'choclo',
                'must_match' => ['/choclo/iu'],
                'must_not_match' => ['/desgranado|congelad|lata|conserva|chifle|pack|mote/iu'],
                'assume_single' => true,
            ],
            'inei' => ['label' => 'CHOCLO CRIOLLO', 'unit' => 'KILOGRAMO'],
        ],
        'culantro' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/818/830/',
                'term' => 'culantro',
                'must_match' => ['/culantro/iu'],
                'must_not_match' => ['/pack|deshidr|pasta|salsa|semilla/iu'],
                'assume_single' => true,
            ],
        ],
        'apio' => [
            'kg_per_unit' => 0.4,
            'plazavea' => [
                'category_path' => '/77/818/829/',
                'term' => 'apio',
                'must_match' => ['/apio/iu'],
                'must_not_match' => ['/pack|picad|deshidr|pasta|salsa|semilla/iu'],
                'assume_single' => true,
            ],
            'inei' => ['label' => 'APIO', 'unit' => 'KILOGRAMO'],
        ],
        'rocoto' => [
            'kg_per_unit' => null,
            'emmsa' => ['prod' => '02', 'product' => 'AJI', 'variety_match' => '/^AJI ROCOTO/iu'],
            'gmml' => ['label' => 'Aji Rocoto'],
        ],
        'pescado-fresco' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/814/887/1614/',
                'term' => 'pescado',
                'must_match' => ['/./iu'],
                'must_not_match' => ['/congelad|ahumad|pack|apanad|elaborado|fil[eé]t|medall|lomo|empaniz|hamburguesa/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'BONITO', 'unit' => 'KILOGRAMO'],
        ],
        'atun-en-lata' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/459/460/',
                'term' => 'atún',
                'must_match' => ['/at[uú]n/iu'],
                'must_not_match' => ['/pack|trio|x ?[23]\b|gato|mascota/iu'],
                'assume_single' => true,
            ],
            'inei' => ['label' => 'FILETE DE ATÚN', 'unit' => 'LATA'],
        ],
        'cafe-molido' => [
            'kg_per_unit' => 0.25,
            'plazavea' => [
                'category_path' => '/478/480/1637/',
                'term' => 'café molido',
                'must_match' => ['/caf[eé]/iu'],
                'must_not_match' => ['/instant|capsul|c[aá]psul|pack|filtrante|descafeinado/iu'],
                'assume_single' => false,
            ],
        ],
        'avena' => [
            'kg_per_unit' => 0.3,
            'plazavea' => [
                'category_path' => '/478/479/1639/',
                'term' => 'avena',
                'must_match' => ['/avena/iu'],
                'must_not_match' => ['/galleta|barra|cereal|granola|pack|bebida|instant/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'AVENA A GRANEL', 'unit' => 'KILOGRAMO'],
        ],
        'quinua' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/431/435/447/',
                'term' => 'quinua',
                'must_match' => ['/quinua|quinoa/iu'],
                'must_not_match' => ['/hojuela|pop|snack|barra|pack|harina|pre ?cocid/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'QUINUA ENTERA', 'unit' => 'KILOGRAMO'],
        ],
        'manzana' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/817/824/',
                'term' => 'manzana',
                'must_match' => ['/manzana/iu'],
                'must_not_match' => ['/pur[eé]|jugo|deshidr|pack|chips|compota|verde.*pack/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'MANZANA CORRIENTE', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '73', 'product' => 'MANZANA', 'variety_match' => '/^MANZANA/iu'],
            'gmml' => ['label' => 'Manzana Cte/Para Agua'],
        ],
        'naranja' => [
            'kg_per_unit' => null,
            'plazavea' => [
                'category_path' => '/77/817/821/',
                'term' => 'naranja',
                'must_match' => ['/naranja/iu'],
                'must_not_match' => ['/jugo.*(botella|caja)|pack|pur[eé]|mermelada|mandarina|pomelo/iu'],
                'assume_single' => false,
            ],
            'inei' => ['label' => 'NARANJA DE JUGO', 'unit' => 'KILOGRAMO'],
            'emmsa' => ['prod' => '78', 'product' => 'NARANJA', 'variety_match' => '/^NARANJA/iu'],
        ],
    ],

];

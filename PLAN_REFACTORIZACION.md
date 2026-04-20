# PLAN DE REFACTORIZACIÓN — Wajaycha Backend Laravel
> **Auditor:** Arquitecto de Software Senior (Agente IA)
> **Fecha de auditoría:** 2026-04-19
> **Versión del plan:** 1.0
> **Estándares de referencia:** `.agents/rules/01-laravel-core.md`, `.agents/rules/02-database-dba.md`

---

## 1. DIAGNÓSTICO EJECUTIVO

### 1.1 Inventario de la Arquitectura Actual

| Capa | Directorio | ¿Existe? | Estado |
|------|-----------|----------|--------|
| Controladores HTTP | `app/Http/Controllers/` | ✅ | **INFLADOS** — Contienen lógica de negocio |
| Servicios | `app/Services/` | ✅ | Parcial — 5 servicios, roles mezclados |
| DTOs | `app/DTOs/` | ✅ | Mínimo — Solo 1 DTO (`TransactionDataDTO`) |
| Repositorios | `app/Repositories/` | ❌ | **AUSENTE** |
| Actions / UseCases | `app/Actions/` | ❌ | **AUSENTE** |
| Pipelines / Policies | `app/Policies/` | ❌ | **AUSENTE** |
| Observers | `app/Observers/` | ✅ | Existe (UserObserver) |
| Jobs (Queue) | `app/Jobs/` | ✅ | 7 Jobs — **con lógica de negocio embebida** |
| Tests Feature | `tests/Feature/` | ✅ | Solo `ExampleTest.php` — **RED DE SEGURIDAD VACÍA** |
| Tests Unit | `tests/Unit/` | ✅ | Solo `ExampleTest.php` — **RED DE SEGURIDAD VACÍA** |

### 1.2 Análisis del Esquema de Base de Datos

#### Tabla `transactions`
- ✅ PK `id` (`bigint`)
- ✅ FK `detail_id`, `user_id`, `category_id`, `yape_id` correctamente definidas
- ⚠️ `amount` usa `decimal(10,2)` — correcto, pero la migración original **no especifica `timestamptz`** para `date_operation`; usa `timestamp` sin zona horaria. Viola la regla **02-database-dba.md §2**.
- ⚠️ `is_subscription` y `is_manual` no tienen el prefijo `is_` en la convención de la migración (aunque el nombre sí lo tiene) — **coherente, no bloqueante**.
- ❌ **Sin comentarios a nivel de base de datos** (`$table->comment()`). Viola la regla **02-database-dba.md §2**.

#### Tabla `categories`
- ✅ Tiene jerarquía `parent_id` self-referencial correctamente diseñada.
- ✅ FK `pareto_classification_id` — la relación Category → ParetoClassification está **correctamente en la capa de persistencia**.
- ⚠️ `monthly_budget` usa `decimal` sin precisión explícita. Debería ser `decimal(15,2)` por la regla de Finanzas.
- ❌ Sin comentarios a nivel de base de datos.

#### Tabla `pareto_classifications`
- ✅ Separada correctamente de `categories` (no hay lógica de Pareto dentro de `Category`).
- ✅ Relación `ParetoClassification → hasMany → Category` bien modelada.
- ⚠️ `percentage` usa `decimal` sin precisión. Debería ser `decimal(5,2)`.
- ❌ La **evaluación de las Reglas de Pareto** (la función SQL `get_pareto_monthly_report`) vive íntegramente en la capa de migración y se llama directamente desde `ParetoClassificationController::index()` con `DB::select()`. **No existe un servicio o repositorio que encapsule esta lógica.**

#### Tablas auxiliares
- `details`: Tiene `embedding` (vector) y `entity_clean` — diseño avanzado. ✅
- `categorization_rules` / `keyword_rules`: Correctamente separadas. ✅
- `details_merge_history`: Auditoría bien pensada. ✅

---

### 1.3 Mapa de "Lógica Pesada" Identificada (Zonas Calientes)

#### 🔴 ZONA CRÍTICA 1 — `ProcessWhatsAppImage.php` (Job, 219 líneas)
**Problemas encontrados:**
- Llama directamente a la API de **Gemini Vision** via `Http::post()` con el prompt hardcodeado dentro del método `handle()`.
- Descarga la imagen de Meta (API Graph) directamente dentro del Job.
- Ejecuta lógica de `Detail::create()`, `Transaction::create()`, y búsqueda por trigramas (`findExistingDetail`) **dentro del mismo Job**.
- El método `findExistingDetail()` está **duplicado** en `ProcessWhatsAppMessage.php` — violación DRY.
- Usa `app(WhatsAppNotificationService::class)` en el método `failed()` — antipatrón de Service Locator.

#### 🔴 ZONA CRÍTICA 2 — `ProcessWhatsAppMessage.php` (Job, 188 líneas)
**Problemas encontrados:**
- Idénticos al anterior: prompt de Gemini hardcodeado en `handle()`.
- Duplicación exacta de `findExistingDetail()`.
- Mezcla: llamada a IA + lógica de `Detail` + lógica de `Transaction` + notificaciones WA.

#### 🟠 ZONA CALIENTE 3 — `TransactionsController.php` (Controlador, 322 líneas)
**Problemas encontrados:**
- Métodos privados `updateTransactionFrequent()` y `updateTransactionWithoutFrequent()` con lógica de negocio compleja directamente en el controlador.
- `updateMatchingYapeTransaction()` es un helper de negocio dentro del controlador.
- `getSummaryByCategory()`: construye un query Eloquent con `DB::raw()` directamente en el controlador.
- Lógica de `Detail::firstOrCreate()` duplicada entre `store()` y `update()`.

#### 🟠 ZONA CALIENTE 4 — `PdfController.php` (Controlador, 219 líneas)
**Problemas encontrados (LEGACY):**
- Parseo de PDF con `TesseractOCR` directamente en el controlador (clase `PdfController`).
- Código duplicado con `ProcessPdfImport.php`: métodos `extractTextFromPdf()`, `isEncrypted()`, `decryptPdf()` son **copia exacta** en ambos archivos.
- Instancia de modelos obsoletos (`App\Models\Details`, `App\Models\Expense`) que posiblemente ya no existen.
- `SELECT *` implícito en algunos casos.

#### 🟡 ZONA TIBIA 5 — `SmartMergeJob.php` (Job, 237 líneas)
**Problemas encontrados:**
- Toda la lógica del "Juez IA" (Entity Resolution) está hardcodeada dentro del Job.
- El prompt para Gemini Pro debería estar en un servicio o archivo de configuración.
- `mergeDetails()` es una operación de negocio compleja que debería ser un `UseCase` o `Action` independiente.

#### 🟡 ZONA TIBIA 6 — `DashboardController.php`
- Todas las funciones delegan a Stored Procedures de PostgreSQL directamente con `DB::select()` — aceptable, pero sin abstracción de repositorio.

#### 🟡 ZONA TIBIA 7 — `CategorizationService.php`
- Es el servicio más complejo y bien estructurado. **Ya hace lo correcto** en términos de principio único de responsabilidad.
- Optimización pendiente: `KeywordRule::where('user_id', $userId)->get()` carga **todas** las reglas cada vez que se llama. Debería usar caché (`Cache::remember`).

---

### 1.4 Violaciones a los Estándares Definidos

| Regla | Violación | Archivos |
|-------|-----------|----------|
| `01-core` — Controladores delgados | Controladores con lógica de negocio | `TransactionsController`, `PdfController` |
| `01-core` — Lógica en Services | Lógica de IA y persistencia en Jobs | `ProcessWhatsAppImage`, `ProcessWhatsAppMessage`, `SmartMergeJob` |
| `01-core` — DTOs para parámetros complejos | Solo existe 1 DTO | Todo el proyecto |
| `01-core` — Prohibido código comentado | `PdfController` tiene imports `use App\Models\Details` (obsoleto) | `PdfController.php` |
| `02-db` — `timestamp` sin TZ | `date_operation` usa `timestamp` sin zona | Migración `create_transactions_table` |
| `02-db` — `decimal` sin precisión | `percentage`, `monthly_budget` sin `(15,2)` | Migraciones de `categories`, `pareto_classifications` |
| `02-db` — Comentarios en BD | Cero comentarios en tablas/columnas | Todas las migraciones |
| `02-db` — No `SELECT *` | `DB::select('SELECT * FROM ...')` | `TransactionsController`, `ParetoClassificationController`, `DashboardController` |
| `02-db` — N+1 Problem | `SmartMergeJob::mergeDetails()` con queries en loop | `SmartMergeJob.php` |

---

## 2. INVENTARIO DE DIRECTORIOS FALTANTES

Para alcanzar la arquitectura limpia definida, se deben crear los siguientes directorios:

```
app/
├── Actions/                    ← CREAR (operaciones de negocio atómicas)
│   ├── Transactions/
│   ├── WhatsApp/
│   └── Pareto/
├── Repositories/               ← CREAR (acceso a datos desacoplado)
│   ├── Contracts/              ← Interfaces de repositorios
│   ├── TransactionRepository.php
│   ├── DetailRepository.php
│   └── ParetoRepository.php
├── DTOs/                       ← AMPLIAR (solo existe TransactionDataDTO)
│   ├── WhatsApp/
│   │   ├── IncomingMessageDTO.php
│   │   └── ParsedReceiptDTO.php
│   └── Pareto/
│       └── ParetoReportFilterDTO.php
└── Policies/                   ← CREAR (autorización explícita)
    └── TransactionPolicy.php
```

---

## 3. ESTRATEGIA INCREMENTAL "SLICE-BY-SLICE"

> **Principio guía:** Nunca refactorizar sin red de seguridad. Cada Fase comienza creando los tests que validan el comportamiento actual ANTES de mover una sola línea de código productivo.

---

## FASE 1 — RED DE SEGURIDAD (Feature Tests de Contrato)

**Objetivo:** Crear el conjunto de tests que documenten el comportamiento actual de la API. Sin este paso, cualquier refactorización es un salto al vacío.

**Duración estimada:** 2 sprints

### Task Group 1.1 — Infraestructura de Tests

- [ ] Configurar `phpunit.xml` para usar una base de datos SQLite en memoria (o PgSQL de test separada) con `RefreshDatabase`.
- [ ] Crear un `UserFactory` completo con `whatsapp_phone`.
- [ ] Crear `CategoryFactory`, `ParetoClassificationFactory`, `TransactionFactory`, `DetailFactory`.
- [ ] Crear un `TestCase` base con helpers: `actingAsJwtUser()`, `createUserWithCategories()`.

### Task Group 1.2 — Tests del Módulo de Transacciones

**Archivo:** `tests/Feature/Transactions/TransactionCrudTest.php`

```php
// Casos a cubrir:
it('puede crear una transacción manual con detail_description nuevo')
it('puede crear una transacción manual con detail_id existente')
it('rechaza crear transacción sin autenticación')
it('puede actualizar una transacción manual propia')
it('no puede actualizar una transacción no-manual')
it('no puede actualizar una transacción de otro usuario')
it('puede eliminar una transacción manual propia')
it('no puede eliminar una transacción no-manual')
it('puede listar transacciones paginadas con filtros de mes y año')
it('el endpoint get-summary-by-category retorna datos agrupados')
```

### Task Group 1.3 — Tests del Módulo de Pareto

**Archivo:** `tests/Feature/Pareto/ParetoClassificationTest.php`

```php
// Casos a cubrir:
it('puede crear una clasificación pareto')
it('puede listar clasificaciones pareto del usuario autenticado')
it('no puede eliminar pareto con categorías asignadas')
it('puede obtener las categorías de una clasificación pareto')
it('el reporte mensual pareto devuelve la estructura correcta')
```

### Task Group 1.4 — Tests del Módulo WhatsApp (Webhook)

**Archivo:** `tests/Feature/WhatsApp/WhatsAppWebhookTest.php`

```php
// Casos a cubrir:
it('verifica el webhook con token correcto devuelve el challenge')
it('rechaza la verificación con token incorrecto')
it('despacha ProcessWhatsAppMessage al recibir un mensaje de texto')
it('despacha ProcessWhatsAppImage al recibir un mensaje de imagen')
it('devuelve 200 inmediatamente aunque el payload sea inválido')
```
> **Nota técnica:** Usar `Queue::fake()` para verificar que el Job se despachó sin ejecutarlo realmente.

### Task Group 1.5 — Tests del Módulo de Importación

**Archivo:** `tests/Feature/Imports/ImportPdfTest.php`

```php
// Casos a cubrir:
it('sube un PDF y crea un registro de Import con status pending')
it('despacha ProcessPdfImport al subir un archivo')
it('puede listar los imports del usuario autenticado')
it('puede descargar el archivo de un import')
```

---

## FASE 2 — REFACTORIZACIÓN DEL MÓDULO WHATSAPP (Slice más crítico)

**Prerequisito:** ✅ Tests de la Fase 1 pasando en verde.
**Objetivo:** Eliminar la lógica de negocio de los Jobs de WhatsApp y desacoplar la integración con Gemini y Meta.

### Task Group 2.1 — Extraer GeminiVisionService

**Archivo nuevo:** `app/Services/GeminiVisionService.php`

Responsabilidades:
- Recibir `imageBytes` (string) y `mimeType` (string).
- Contener el prompt del sistema para análisis de recibos.
- Llamar a la API de Gemini y retornar un `ParsedReceiptDTO`.
- Lanzar excepción tipada `GeminiVisionException` en caso de fallo.

```php
// Interfaz esperada:
interface GeminiVisionServiceContract {
    public function analyzeReceipt(string $imageBytes, string $mimeType): ParsedReceiptDTO;
}
```

### Task Group 2.2 — Extraer GeminiTextService

**Archivo nuevo:** `app/Services/GeminiTextService.php`

Responsabilidades:
- Contener el prompt para análisis de texto financiero.
- Llamar a Gemini API y retornar un `ParsedReceiptDTO` (mismo DTO, reutilizable).
- Lanzar excepción tipada `GeminiTextException`.

### Task Group 2.3 — Crear DTOs de WhatsApp

**Archivos nuevos:**
- `app/DTOs/WhatsApp/ParsedReceiptDTO.php` — dato de retorno del análisis de IA.
- `app/DTOs/WhatsApp/IncomingMessageDTO.php` — datos del webhook entrante.

### Task Group 2.4 — Extraer MetaMediaService

**Archivo nuevo:** `app/Services/MetaMediaService.php`

Responsabilidades:
- Descargar la URL de un mediaId desde la API de Meta Graph.
- Descargar los bytes de imagen desde esa URL.
- Encapsular el token de WhatsApp.

### Task Group 2.5 — Crear Action: RegisterWhatsAppTransaction

**Archivo nuevo:** `app/Actions/WhatsApp/RegisterWhatsAppTransactionAction.php`

Responsabilidades:
- Recibir un `ParsedReceiptDTO` y un `User`.
- Orquestar: `TransactionAnalyzer` → `DetailRepository::findOrCreateByEntity()` → `CategorizationService::findCategory()` → `Transaction::create()`.
- **Este Action elimina la lógica duplicada de `findExistingDetail()` que hoy existe en ambos Jobs.**

### Task Group 2.6 — Refactorizar Jobs (Solo Orquestadores)

**Archivos a modificar:**
- `ProcessWhatsAppImage.php` — reducir a: identificar usuario → llamar `MetaMediaService` → llamar `GeminiVisionService` → llamar `RegisterWhatsAppTransactionAction` → notificar.
- `ProcessWhatsAppMessage.php` — reducir a: identificar usuario → llamar `GeminiTextService` → llamar `RegisterWhatsAppTransactionAction` → notificar.

**Resultado esperado:** Cada Job debe quedar en < 60 líneas.

### Task Group 2.7 — Tests Unitarios del Módulo WhatsApp

```php
// tests/Unit/WhatsApp/RegisterWhatsAppTransactionActionTest.php
it('crea un nuevo detail si no existe coincidencia por trigrama')
it('reutiliza un detail existente si similarity > 0.6')
it('asigna la categoría correcta usando CategorizationService')
it('persiste la transacción con los datos del DTO')

// tests/Unit/Services/GeminiVisionServiceTest.php
it('lanza GeminiVisionException si la respuesta no es exitosa')
it('retorna ParsedReceiptDTO cuando la respuesta es válida')
it('retorna is_valid_receipt=false si Gemini no detecta un comprobante')
```

---

## FASE 3 — REFACTORIZACIÓN DEL MÓDULO DE TRANSACCIONES Y PDF

**Prerequisito:** ✅ Tests de Fase 1 y 2 pasando en verde.
**Objetivo:** Desinflar `TransactionsController` y eliminar código duplicado del procesamiento de PDFs.

### Task Group 3.1 — Crear TransactionRepository

**Archivo nuevo:** `app/Repositories/TransactionRepository.php`
**Interfaz:** `app/Repositories/Contracts/TransactionRepositoryContract.php`

Métodos a extraer:
- `findPaginated(TransactionFilterDTO $filters): LengthAwarePaginator`
- `findOrCreateDetail(int $userId, string $description): Detail`
- `summaryByCategory(int $userId, ?int $year, ?int $month): Collection`

### Task Group 3.2 — Crear Actions de Transacciones

**Archivos nuevos:**
- `app/Actions/Transactions/StoreTransactionAction.php` — Contiene la lógica de `Detail::firstOrCreate()` + `Transaction::create()`.
- `app/Actions/Transactions/UpdateTransactionAction.php` — Contiene `updateTransactionFrequent()` y `updateTransactionWithoutFrequent()`.

### Task Group 3.3 — Crear TransactionFilterDTO

**Archivo nuevo:** `app/DTOs/Transactions/TransactionFilterDTO.php`

```php
final class TransactionFilterDTO {
    public function __construct(
        public readonly int $userId,
        public readonly int $perPage,
        public readonly int $page,
        public readonly ?int $year,
        public readonly ?int $month,
        // ... etc
    ) {}
}
```

### Task Group 3.4 — Extraer PdfParserService (Eliminar Duplicación)

**Problema:** `extractTextFromPdf()`, `isEncrypted()`, `decryptPdf()` están **duplicados** exactos en `PdfController` y `ProcessPdfImport`.

**Archivo nuevo:** `app/Services/PdfParserService.php`

- Consolidar los tres métodos privados en este servicio.
- Inyectar `PdfParserService` en `ProcessPdfImport`.
- **Evaluar si `PdfController` sigue siendo necesario** (su función principal —parseo directo desde el controlador— fue reemplazada por `ImportController` + `ProcessPdfImport`). Si no, marcarlo como DEPRECATED y programar su eliminación.

### Task Group 3.5 — Delgadez del Controlador

Después de crear los Actions y el Repository, `TransactionsController` debe quedar como un puro orquestador:

```php
// Ejemplo del store() refactorizado:
public function store(StoreTransactionRequest $request, StoreTransactionAction $action): JsonResponse
{
    $dto = TransactionFilterDTO::fromRequest($request, Auth::id());
    $transaction = $action->execute($dto);
    return response()->json($transaction, 201);
}
```

### Task Group 3.6 — TransactionPolicy (Autorización)

**Archivo nuevo:** `app/Policies/TransactionPolicy.php`

Reemplazar la lógica de autorización manual (`if (!$transaction->is_manual)`) con Policies de Laravel:

```php
// En vez de if (!$transaction->is_manual) return 403;
// Usar:
$this->authorize('update', $transaction);
```

---

## FASE 4 — DEUDA TÉCNICA DE BASE DE DATOS Y CACHÉ

**Prerequisito:** ✅ Fases 1, 2 y 3 completadas y con tests verdes.
**Objetivo:** Cerrar las brechas de los estándares `02-database-dba.md` y optimizar rendimiento.

### Task Group 4.1 — Migración Correctiva: `timestamptz` para `date_operation`

**Archivo nuevo de migración:** `YYYY_MM_DD_alter_transactions_date_operation_to_timestamptz.php`

```php
// Cambiar el tipo de timestamp a timestamptz (con zona horaria)
$table->timestampTz('date_operation')->change();
```

> ⚠️ **Riesgo:** Esta migración puede afectar datos existentes. Ejecutar primero en entorno de staging.

### Task Group 4.2 — Migración Correctiva: Precisión de Decimales

**Archivo nuevo de migración:**

```php
// categories.monthly_budget
$table->decimal('monthly_budget', 15, 2)->default(0.00)->change();

// pareto_classifications.percentage
$table->decimal('percentage', 5, 2)->nullable()->change();
```

### Task Group 4.3 — Migración de Comentarios en BD

Agregar comentarios de metadatos a las tablas principales según `02-database-dba.md §2`:

```php
$table->comment('Registra cada transacción financiera del usuario (gastos e ingresos)');
// Columnas clave:
// amount       → 'Monto de la operación en soles (PEN). Siempre positivo.'
// date_operation → 'Fecha y hora exacta de la operación con zona horaria.'
// is_manual    → 'Indica si la transacción fue registrada manualmente por el usuario.'
```

### Task Group 4.4 — Caché en CategorizationService

**Problema:** `KeywordRule::where('user_id', $userId)->get()` ejecuta una query por cada categorización.

**Solución:**

```php
// En CategorizationService::findCategory()
$keywordRules = Cache::remember(
    "keyword_rules_{$userId}",
    now()->addMinutes(60),
    fn() => KeywordRule::where('user_id', $userId)->get()
);
```

> Invalidar el caché en `KeywordRule::saved()` y `KeywordRule::deleted()` con un Observer.

### Task Group 4.5 — Crear SmartMergeAction (Desacoplar SmartMergeJob)

**Archivos nuevos:**
- `app/Actions/Details/MergeDetailsAction.php` — contiene la lógica de `mergeDetails()`.
- `app/Services/EntityResolutionService.php` — contiene `buildClusterPrompt()` y llama a Gemini Pro.

`SmartMergeJob` debe quedar en < 80 líneas y solo orquestar el loop + llamadas a estos componentes.

### Task Group 4.6 — Índices de Rendimiento Faltantes

Agregar en una nueva migración los índices sugeridos para queries frecuentes:

```sql
-- idx_transactions_user_date (para filtros de mes/año)
CREATE INDEX idx_transactions_user_date ON transactions(user_id, date_operation);

-- idx_transactions_category (para agrupaciones por categoría)
CREATE INDEX idx_transactions_category ON transactions(category_id);

-- idx_details_entity_clean (para búsquedas de similitud)
CREATE INDEX idx_details_entity_clean ON details(user_id, entity_clean);

-- idx_categorization_rules_lookup (lookup crítico en categorización)
CREATE INDEX idx_categorization_rules_lookup ON categorization_rules(user_id, detail_id);
```

---

## 4. MAPA DE DEPENDENCIAS ENTRE FASES

```
[FASE 1: Red de Seguridad]
        │
        ▼
[FASE 2: WhatsApp] ──── Se puede hacer en paralelo con FASE 3
        │                                  │
        │                                  ▼
        │                  [FASE 3: Transactions + PDF]
        │                                  │
        └──────────────────────────────────┘
                           │
                           ▼
                  [FASE 4: DB + Caché + Índices]
```

---

## 5. RESUMEN DE ARCHIVOS A CREAR / MODIFICAR / ELIMINAR

### CREAR (Nuevos)
| Archivo | Tipo | Fase |
|---------|------|------|
| `app/Actions/WhatsApp/RegisterWhatsAppTransactionAction.php` | Action | 2 |
| `app/Actions/Transactions/StoreTransactionAction.php` | Action | 3 |
| `app/Actions/Transactions/UpdateTransactionAction.php` | Action | 3 |
| `app/Actions/Details/MergeDetailsAction.php` | Action | 4 |
| `app/Repositories/TransactionRepository.php` | Repository | 3 |
| `app/Repositories/Contracts/TransactionRepositoryContract.php` | Interface | 3 |
| `app/Repositories/DetailRepository.php` | Repository | 2+3 |
| `app/Services/GeminiVisionService.php` | Service | 2 |
| `app/Services/GeminiTextService.php` | Service | 2 |
| `app/Services/MetaMediaService.php` | Service | 2 |
| `app/Services/PdfParserService.php` | Service | 3 |
| `app/Services/EntityResolutionService.php` | Service | 4 |
| `app/DTOs/WhatsApp/ParsedReceiptDTO.php` | DTO | 2 |
| `app/DTOs/WhatsApp/IncomingMessageDTO.php` | DTO | 2 |
| `app/DTOs/Transactions/TransactionFilterDTO.php` | DTO | 3 |
| `app/DTOs/Pareto/ParetoReportFilterDTO.php` | DTO | 3 |
| `app/Policies/TransactionPolicy.php` | Policy | 3 |
| `tests/Feature/Transactions/TransactionCrudTest.php` | Test | 1 |
| `tests/Feature/Pareto/ParetoClassificationTest.php` | Test | 1 |
| `tests/Feature/WhatsApp/WhatsAppWebhookTest.php` | Test | 1 |
| `tests/Feature/Imports/ImportPdfTest.php` | Test | 1 |
| `tests/Unit/WhatsApp/RegisterWhatsAppTransactionActionTest.php` | Test | 2 |
| `tests/Unit/Services/GeminiVisionServiceTest.php` | Test | 2 |

### MODIFICAR (Refactorizar)
| Archivo | Cambio | Fase |
|---------|--------|------|
| `app/Http/Controllers/TransactionsController.php` | Delegar a Actions y Repository | 3 |
| `app/Jobs/ProcessWhatsAppImage.php` | Reducir a orquestador < 60 líneas | 2 |
| `app/Jobs/ProcessWhatsAppMessage.php` | Reducir a orquestador < 60 líneas | 2 |
| `app/Jobs/ProcessPdfImport.php` | Usar `PdfParserService` | 3 |
| `app/Jobs/SmartMergeJob.php` | Delegar a `MergeDetailsAction` y `EntityResolutionService` | 4 |
| `app/Services/CategorizationService.php` | Agregar caché de `KeywordRule` | 4 |

### EVALUAR PARA ELIMINAR
| Archivo | Razón | Fase |
|---------|-------|------|
| `app/Http/Controllers/PdfController.php` | Funcionalidad redundante con `ImportController` + `ProcessPdfImport` | 3 |

### NUEVAS MIGRACIONES DB
| Descripción | Fase |
|-------------|------|
| `alter_transactions_date_operation_to_timestamptz` | 4 |
| `alter_decimals_precision_categories_pareto` | 4 |
| `add_table_comments_to_main_tables` | 4 |
| `add_performance_indexes` | 4 |

---

## 6. CRITERIOS DE ÉXITO POR FASE

| Fase | Criterio Cuantificable |
|------|------------------------|
| Fase 1 | `php artisan test` reporta > 30 tests pasando. Cobertura de los 4 módulos críticos. |
| Fase 2 | `ProcessWhatsAppImage` y `ProcessWhatsAppMessage` tienen < 60 líneas cada uno. Método `findExistingDetail` existe en un solo lugar. |
| Fase 3 | `TransactionsController` tiene < 80 líneas. Zero lógica de negocio en métodos privados del controlador. |
| Fase 4 | `EXPLAIN ANALYZE` de la query de categorización muestra uso de índice. `CategorizationService` no ejecuta más de 2 queries por transacción. |

---

## 7. REGLAS DE TRABAJO DURANTE LA REFACTORIZACIÓN

1. **No refactorizar sin tests verdes.** Si un test falla antes de refactorizar, es un bug ya existente — documentarlo y corregirlo primero.
2. **Un Task Group = Un commit atómico.** Cada grupo de tareas debe tener su propio commit con mensaje descriptivo.
3. **No mezclar Task Groups.** Completar un grupo antes de empezar el siguiente.
4. **`declare(strict_types=1)` en todo archivo nuevo** — sin excepciones.
5. **Los Jobs solo orquestan.** Si un método en un Job hace más que llamar a un servicio y notificar, debe ser extraído.
6. **Prohibido `dd()`, `dump()` y `Log::info()` de desarrollo** en el código final commiteado.

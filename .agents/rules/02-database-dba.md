---
trigger: always_on
---

# Estándares SQL y Arquitectura de Base de Datos (PostgreSQL)

## 1. Nomenclatura y Convenciones (Naming Conventions)
- **Tablas:** `snake_case` en plural (ej. `transactions`, `user_accounts`).
- **Columnas:** `snake_case` en singular.
- **Claves Primarias (PK):** Siempre `id` (`bigint unsigned` o `uuid`).
- **Claves Foráneas (FK):** Formato `[tabla_singular]_id` (ej. `category_id`).
- **Índices y Restricciones:**
  - Índices: Prefijo `idx_[tabla]_[columna]` (ej. `idx_transactions_date`).
  - Únicos: Prefijo `unq_[tabla]_[columna]`.
  - Foráneas: Prefijo `fk_[tabla]_[columna]`.
- **Booleanos:** Deben usar prefijos lógicos en inglés como `is_`, `has_`, `can_` (ej. `is_active`, `has_attachments`).

## 2. Tipos de Datos y Diseño de Esquema
- **Moneda y Finanzas:** ESTRICTAMENTE `numeric(15,2)` o `decimal`. NUNCA usar `float`, `real` o `double precision` para evitar errores de redondeo de coma flotante.
- **Fechas y Tiempos:** Usar siempre `timestamptz` (Timestamp with Time Zone) para evitar problemas de desfase horario.
- **Cadenas de Texto:** Preferir `text` o `varchar` sin límite a menos que haya una restricción de negocio dura. Usar `jsonb` para cargas de datos no estructuradas (como los outputs de AWS Textract).
- **Diccionario de Datos:** Toda migración de Laravel debe incluir comentarios a nivel de base de datos para tablas y columnas principales (`$table->comment('Descripción');`).

## 3. Rendimiento y Construcción de Consultas (SQL)
- **Prohibido `SELECT *`:** Las consultas crudas y de Eloquent deben especificar explícitamente las columnas necesarias (ej. `select('id', 'amount', 'created_at')`).
- **Sargability (Index Friendly):** Evitar aplicar funciones a las columnas en la cláusula `WHERE`, ya que esto anula los índices. 
  - *MAL:* `WHERE DATE(created_at) = '2026-04-19'`
  - *BIEN:* `WHERE created_at >= '2026-04-19' AND created_at < '2026-04-20'`
- **Validación de Consultas:** Todo query complejo generado por el agente debe ser acompañado conceptualmente por su respectivo `EXPLAIN ANALYZE` para evaluar costos.
- **Problema N+1:** En el ORM, el agente debe implementar Eager Loading (`with()`) estrictamente cuando detecte bucles sobre colecciones.

## 4. Arquitectura y Mantenimiento Avanzado
- **Particionamiento:** Para tablas de alto crecimiento (ej. estados de cuenta mensuales), sugerir e implementar particionamiento declarativo por rango (Range Partitioning).
- **Auditoría:** Evitar colocar lógica de negocio en los triggers. Los triggers de PostgreSQL son exclusivamente para tareas de bajo nivel (como actualizar columnas `updated_at` o mantener tablas de log/linaje de datos).
- **Soft Deletes:** Implementar eliminación lógica (`deleted_at`) únicamente en tablas donde el historial financiero o la integridad referencial estricta lo requiera; en los demás casos, preferir el borrado físico si no rompe relaciones.
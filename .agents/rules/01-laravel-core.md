---
trigger: always_on
---

# Reglas Core de Desarrollo (Laravel & PHP)

## Principios de Código
- **Clean Architecture:** Mantener controladores delgados. La lógica de negocio pertenece a los `Services` o `UseCases`. El acceso a datos se hace mediante `Repositories`.
- **Tipado Estricto:** Obligatorio usar `declare(strict_types=1);` en todo archivo PHP nuevo.
- **Minimalismo Digital:** No instalar paquetes de Composer o NPM si la funcionalidad se puede resolver con código nativo o herramientas integradas de Laravel en menos de 50 líneas.

## Estructura
- Usar **DTOs (Data Transfer Objects)** para pasar parámetros complejos entre controladores y servicios.
- Los trabajos asíncronos pesados (como procesar los PDFs) siempre deben encolarse usando Redis.
- Prohibido dejar código comentado (`//`) o `dd()`, `dump()`, `console.log()` en las confirmaciones finales.
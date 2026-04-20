---
description: Scaffold de Nueva Funcionalidad
---

# Workflow: Scaffold de Nueva Funcionalidad

## Trigger
Cuando el usuario solicite "Crear un nuevo módulo para [Entidad]" o similar.

## Secuencia de Pasos
1. **Análisis:** Confirmar los campos de la tabla requerida.
2. **Base de Datos:** Generar la Migración (aplicando convenciones de PostgreSQL).
3. **Persistencia:** Generar el Modelo y el Repositorio (Interface + Implementación).
4. **Transporte:** Generar el FormRequest y el DTO.
5. **Negocio:** Generar el Caso de Uso / Action.
6. **Interfaz:** Generar el Controlador de API o Web.
7. **Testing:** Generar el archivo de pruebas Feature y escribir al menos un test básico de creación.
8. **Revisión:** Presentar el listado de archivos generados en el editor para la aprobación del desarrollador.
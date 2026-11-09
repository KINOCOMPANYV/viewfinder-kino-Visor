# Viewfinder Kino Visor

Centro de contenido para distribuidores. Busca por SKU, consulta fichas de producto, y descarga fotos/videos.

## Requisitos

- PHP 8.1+
- MySQL 8.0
- Composer
- Docker (para Railway)

## Desarrollo local

```bash
# 1. Instalar dependencias
composer install

# 2. Copiar variables de entorno
cp .env.example .env
# Editar .env con tus credenciales de MySQL local

# 3. Ejecutar migraciones
php migrate.php

# 4. Iniciar servidor
php -S localhost:8080
```

## Deploy en Railway

1. Crear proyecto en [Railway](https://railway.app)
2. Agregar plugin **MySQL**
3. Conectar tu repositorio GitHub
4. Configurar variables de entorno (ver `.env.example`)
5. Deploy automático con cada `git push`

## Catálogo

Los productos salen de la hoja maestra de Google (Admin → Sincronizar) y de los archivos de Drive.
Ver `docs/AVANCES.md` (estado y decisiones) y `docs/ARQUITECTURA.md` (cómo funciona).

## Estructura

```
├── index.php           # Router principal
├── config/             # Configuración (DB, app, storage)
├── src/controllers/    # Lógica de negocio
├── src/helpers.php     # Funciones reutilizables
├── templates/          # Vistas HTML (client + admin)
├── migrations/         # SQL de creación de tablas
├── assets/             # CSS, JS estáticos
└── Dockerfile          # Para deploy en Railway
```

# SAT CSF PDF Generator

Generador de Constancia de Situación Fiscal (CSF) del SAT de México.

## 🚀 Características

- ✅ Generación automática de PDF con datos del SAT
- ✅ Código QR y código de barras integrados
- ✅ Cadena Original y Sello Digital simulados
- ✅ Diseño oficial con colores institucionales
- ✅ Responsive y optimizado

## 📋 Requisitos

- PHP 8.3+
- Navegador moderno con soporte para ES6+

## 🛠️ Instalación Local

```bash
# Clonar el repositorio
git clone [URL]

# Navegar al directorio
cd satcsfc

# Instalar dependencias (opcional, para desarrollo)
npm install

# Iniciar servidor PHP local
php -S localhost:8080
```

## 🏗️ Build para Producción

```bash
# Minificar JavaScript
npm run build
```

## 📦 Estructura del Proyecto

```
satcsfc/
├── index.php           # Aplicación principal
├── rfcblanco.pdf       # Plantilla PDF
├── css/
│   └── styles.css      # Estilos personalizados
├── js/
│   └── app.js          # Lógica JS (desarrollo)
└── build/
    └── app.min.js      # Lógica JS (producción)
```

## 🔐 Seguridad

⚠️ **Nota Importante**: El Sello Digital generado es **simulado** con fines de demostración. No es criptográficamente válido ya que requeriría la llave privada del SAT.

## 📄 Licencia
Proyecto de demostración educativa.
Desarrollado por: **ElEnyelDev** 
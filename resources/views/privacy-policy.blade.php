<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidad | Wajaycha</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --bg: #f8fafc;
            --text: #1e293b;
            --text-light: #64748b;
            --card-bg: #ffffff;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f172a;
                --text: #f1f5f9;
                --text-light: #94a3b8;
                --card-bg: #1e293b;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.6;
            transition: background-color 0.3s ease;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 4rem 1.5rem;
        }

        header {
            text-align: center;
            margin-bottom: 4rem;
            animation: fadeIn 0.8s ease-out;
        }

        .logo {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.05em;
            margin-bottom: 0.5rem;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .content {
            background-color: var(--card-bg);
            padding: 3rem;
            border-radius: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
            animation: slideUp 0.8s ease-out;
        }

        h2 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-top: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        h2:first-child {
            margin-top: 0;
        }

        p {
            margin-bottom: 1.25rem;
            color: var(--text);
            font-weight: 400;
        }

        ul {
            margin-bottom: 1.25rem;
            padding-left: 1.5rem;
        }

        li {
            margin-bottom: 0.5rem;
        }

        .contact-info {
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid var(--text-light);
            text-align: center;
            opacity: 0.8;
        }

        .contact-info a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .contact-info a:hover {
            text-decoration: underline;
        }

        footer {
            text-align: center;
            margin-top: 4rem;
            color: var(--text-light);
            font-size: 0.875rem;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 640px) {
            .content {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">Wajaycha</div>
            <h1>Política de Privacidad</h1>
        </header>

        <main class="content">
            <p><strong>Última actualización:</strong> {{ now()->format('d/m/Y') }}</p>

            <p>En <strong>Wajaycha</strong>, nos tomamos muy en serio tu privacidad. Esta Política de Privacidad describe cómo recopilamos, usamos y protegemos tu información cuando utilizas nuestros servicios, especialmente a través de nuestra integración con WhatsApp y Meta.</p>

            <h2>1. Información que Recopilamos</h2>
            <p>Para proporcionar nuestros servicios, podemos recopilar la siguiente información:</p>
            <ul>
                <li><strong>Información de contacto:</strong> Tu número de teléfono de WhatsApp.</li>
                <li><strong>Contenido de los mensajes:</strong> Los mensajes, imágenes y archivos que envías al bot para su procesamiento (por ejemplo, para OCR o análisis de gastos).</li>
                <li><strong>Datos técnicos:</strong> Identificadores de usuario proporcionados por Meta/WhatsApp necesarios para la comunicación.</li>
            </ul>

            <h2>2. Cómo Utilizamos tu Información</h2>
            <p>Utilizamos la información recopilada exclusivamente para:</p>
            <ul>
                <li>Procesar tus solicitudes y proporcionar respuestas automáticas.</li>
                <li>Realizar análisis de gastos y categorización financiera según tus instrucciones.</li>
                <li>Mejorar la precisión de nuestras herramientas de procesamiento de imágenes y texto.</li>
                <li>Mantener la seguridad y prevenir el uso indebido de nuestra plataforma.</li>
            </ul>

            <h2>3. Compartición de Datos</h2>
            <p>No vendemos ni alquilamos tu información personal a terceros. Tus datos son compartidos únicamente con:</p>
            <ul>
                <li><strong>Meta/WhatsApp:</strong> Para habilitar la entrega de mensajes a través de sus plataformas.</li>
                <li><strong>Proveedores de servicios en la nube:</strong> Como AWS o Gemini (Google), estrictamente para el procesamiento técnico necesario para el funcionamiento del bot.</li>
            </ul>

            <h2>4. Seguridad de los Datos</h2>
            <p>Implementamos medidas de seguridad técnicas y organizativas para proteger tu información contra acceso no autorizado, alteración o destrucción. Sin embargo, ten en cuenta que ninguna transmisión por internet es 100% segura.</p>

            <h2>5. Tus Derechos</h2>
            <p>Tienes derecho a solicitar el acceso, corrección o eliminación de tus datos personales en cualquier momento. Puedes hacerlo contactándonos a través de los medios indicados a continuación.</p>

            <div class="contact-info">
                <p>Si tienes preguntas sobre esta política, contáctanos en:</p>
                <p><a href="mailto:jpls80032017@gmail.com">jpls80032017@gmail.com</a></p>
            </div>
        </main>

        <footer>
            &copy; {{ date('Y') }} Wajaycha. Todos los derechos reservados.
        </footer>
    </div>
</body>
</html>

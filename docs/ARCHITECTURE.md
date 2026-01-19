# Arquitetura Técnica - Barbara Cleaning

Este documento descreve em detalhes a arquitetura técnica do sistema, incluindo o fluxo de dados, a estrutura do banco de dados e a lógica de cada componente.

---

## 1. Diagrama de Fluxo de Dados

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                              FLUXO DO FUNIL DE LEADS                            │
└─────────────────────────────────────────────────────────────────────────────────┘

   ┌──────────────┐     ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
   │   ANÚNCIO    │────▶│    SITE      │────▶│  FORMULÁRIO  │────▶│  WHATSAPP    │
   │ (Meta/Google)│     │ (WordPress)  │     │   (/quote)   │     │   (Botão)    │
   └──────────────┘     └──────────────┘     └──────────────┘     └──────────────┘
         │                    │                    │                    │
         │                    │                    │                    │
         ▼                    ▼                    ▼                    ▼
   ┌──────────────┐     ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
   │   Captura    │     │ ViewContent  │     │    Lead      │     │   Contact    │
   │ UTMs/GCLID   │     │   (Meta)     │     │ (Meta+Google)│     │ (Meta+Google)│
   └──────────────┘     └──────────────┘     └──────────────┘     └──────────────┘
                                                   │                    │
                                                   ▼                    ▼
                                            ┌──────────────────────────────┐
                                            │      BANCO DE DADOS          │
                                            │        (bc_leads)            │
                                            └──────────────────────────────┘
                                                         │
                                                         ▼
                                            ┌──────────────────────────────┐
                                            │        DASHBOARD             │
                                            │   (leads-dashboard.php)      │
                                            └──────────────────────────────┘
                                                   │           │
                                                   ▼           ▼
                                            ┌───────────┐ ┌───────────┐
                                            │ Schedule  │ │ Purchase  │
                                            │  (Meta)   │ │  (Meta)   │
                                            └───────────┘ └───────────┘
```

---

## 2. Estrutura de Diretórios

```
/dashboard/                        # Raiz do backend
│
├── _docs/                         # Documentação e snippets
│   └── elementor-snippets/        # Códigos para inserir no Elementor
│       ├── Form Completo, site.txt    # Formulário completo (HTML+CSS+JS)
│       ├── Form Script.txt            # Script de InitiateCheckout
│       └── Pixel.txt                  # Script de carregamento do pixel
│
├── _private/                      # Arquivos sensíveis (NÃO versionar)
│   ├── admin-config.php           # Senha do dashboard
│   ├── db-config.php              # Credenciais do banco de dados
│   ├── pixel_config.json          # Configurações do pixel
│   └── pixel_actions.log          # Log de ações do pixel
│
├── lib/                           # Bibliotecas compartilhadas
│   └── bc-capi.php                # Funções para Meta Conversions API
│
├── sql/                           # Schemas do banco de dados
│   ├── schema_bc_leads.sql        # Estrutura da tabela bc_leads
│   ├── schema_bc_pixels.sql       # Estrutura da tabela bc_pixels
│   └── schema_bc_google_ads.sql   # Estrutura da tabela bc_google_ads
│
├── admin-auth.php                 # Sistema de autenticação do dashboard
├── contact-endpoint.php           # Endpoint para evento Contact
├── eircode-lookup.php             # Busca de endereço por Eircode (opcional)
├── google-ads-dashboard.php       # Dashboard de configuração do Google Ads
├── index.php                      # Redireciona para leads-dashboard.php
├── lead-endpoint.php              # Endpoint para evento Lead (A CORRIGIR)
├── leads-dashboard.php            # Dashboard principal de leads
├── pixels-dashboard.php           # Dashboard de configuração de pixels
├── tracking-loader.php            # Carregador dinâmico de scripts de rastreamento
└── ping.txt                       # Arquivo de teste de conectividade
```

---

## 3. Estrutura do Banco de Dados

### Tabela `bc_leads`

Esta é a tabela principal que armazena todos os leads.

| Coluna | Tipo | Descrição |
| :--- | :--- | :--- |
| `id` | INT UNSIGNED AUTO_INCREMENT | Identificador único do lead |
| `name` | VARCHAR(191) | Nome completo do cliente |
| `email` | VARCHAR(191) | Email do cliente |
| `phone` | VARCHAR(50) | Telefone no formato E.164 (+353...) |
| `service_type` | VARCHAR(100) | Tipo de serviço (regular, deep, end_of_tenancy) |
| `bedrooms` | VARCHAR(20) | Número de quartos |
| `bathrooms` | VARCHAR(20) | Número de banheiros |
| `went_whatsapp` | TINYINT(1) | 1 se clicou no WhatsApp, 0 caso contrário |
| `scheduled_for` | DATETIME | Data/hora do agendamento |
| `scheduled_value` | DECIMAL(10,2) | Valor do orçamento agendado |
| `paid_value` | DECIMAL(10,2) | Valor pago pelo cliente |
| `paid_at` | DATETIME | Data/hora do pagamento |
| `created_at` | TIMESTAMP | Data de criação do lead |
| `fbclid` | VARCHAR(255) | Facebook Click ID |
| `gclid` | VARCHAR(255) | Google Click ID |
| `gbraid` | VARCHAR(255) | Google Ads App Campaign ID |
| `wbraid` | VARCHAR(255) | Google Ads Web Campaign ID |
| `utm_source` | VARCHAR(191) | Parâmetro UTM source |
| `utm_medium` | VARCHAR(191) | Parâmetro UTM medium |
| `utm_campaign` | VARCHAR(191) | Parâmetro UTM campaign |
| `utm_content` | VARCHAR(191) | Parâmetro UTM content |
| `utm_term` | VARCHAR(191) | Parâmetro UTM term |
| `fbp` | VARCHAR(255) | Cookie _fbp do Meta Pixel |
| `fbc` | VARCHAR(255) | Cookie _fbc do Meta Pixel |
| `page_url` | TEXT | URL da página onde o formulário foi preenchido |
| `referrer` | TEXT | URL de referência |
| `user_agent` | TEXT | User Agent do navegador |
| `ip_address` | VARCHAR(64) | Endereço IP do cliente |
| `lead_event_id` | VARCHAR(64) | ID do evento Lead para deduplicação |
| `lead_event_sent` | TINYINT(1) | 1 se o evento Lead foi enviado via CAPI |
| `contact_event_id` | VARCHAR(64) | ID do evento Contact para deduplicação |
| `contact_event_sent` | TINYINT(1) | 1 se o evento Contact foi enviado via CAPI |
| `scheduled_event_id` | VARCHAR(64) | ID do evento Schedule para deduplicação |
| `scheduled_event_sent` | TINYINT(1) | 1 se o evento Schedule foi enviado via CAPI |
| `purchase_event_id` | VARCHAR(64) | ID do evento Purchase para deduplicação |
| `purchase_event_sent` | TINYINT(1) | 1 se o evento Purchase foi enviado via CAPI |
| `eircode` | VARCHAR(20) | Código postal irlandês |
| `address_line1` | VARCHAR(191) | Linha 1 do endereço |
| `address_line2` | VARCHAR(191) | Linha 2 do endereço |
| `city` | VARCHAR(100) | Cidade |
| `county` | VARCHAR(100) | Condado |

### Tabela `bc_pixels`

Gerencia os Pixels do Meta.

| Coluna | Tipo | Descrição |
| :--- | :--- | :--- |
| `id` | INT UNSIGNED AUTO_INCREMENT | Identificador único |
| `pixel_id` | VARCHAR(32) | ID do Pixel do Meta |
| `pixel_name` | VARCHAR(191) | Nome descritivo do pixel |
| `access_token` | TEXT | Token de acesso para a CAPI |
| `test_code` | VARCHAR(100) | Código de teste (Test Events) |
| `is_active` | TINYINT(1) | 1 se ativo, 0 se inativo |
| `created_at` | TIMESTAMP | Data de criação |

### Tabela `bc_google_ads`

Gerencia as configurações do Google Ads.

| Coluna | Tipo | Descrição |
| :--- | :--- | :--- |
| `id` | INT UNSIGNED AUTO_INCREMENT | Identificador único |
| `tag_name` | VARCHAR(191) | Nome descritivo da tag |
| `conversion_id` | VARCHAR(50) | ID de conversão (AW-XXXXXXX) |
| `lead_label` | VARCHAR(100) | Label da conversão Lead |
| `contact_label` | VARCHAR(100) | Label da conversão Contact |
| `schedule_label` | VARCHAR(100) | Label da conversão Schedule |
| `is_active` | TINYINT(1) | 1 se ativo, 0 se inativo |
| `created_at` | TIMESTAMP | Data de criação |

---

## 4. Detalhamento dos Endpoints

### `lead-endpoint.php`

**Método:** POST

**Content-Type:** `application/json` ou `text/plain;charset=UTF-8`

**Payload esperado:**

```json
{
  "source": "cleaning_quote_form",
  "timestamp": "2025-01-19T12:00:00.000Z",
  "event_id": "lead_1737288000000_abc123def",
  "data": {
    "first_name": "John",
    "last_name": "Doe",
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "+353871234567",
    "phone_digits": "353871234567",
    "service_type": "deep_cleaning",
    "bedrooms": "3",
    "bathrooms": "2",
    "eircode": "D02 X285",
    "address_line1": "123 Main Street",
    "city": "Dublin",
    "county": "Dublin",
    "country": "IE"
  },
  "meta": {
    "page_url": "https://barbaracleaning.ie/quote/",
    "referrer": "https://www.google.com/",
    "user_agent": "Mozilla/5.0...",
    "fbclid": "...",
    "fbp": "fb.1.1737288000.1234567890",
    "fbc": "fb.1.1737288000.fbclid_value",
    "gclid": "...",
    "utm_source": "google",
    "utm_medium": "cpc",
    "utm_campaign": "cleaning_dublin"
  }
}
```

**Resposta de sucesso:**

```json
{
  "ok": true,
  "id": 123,
  "capi": {
    "ok": true,
    "results": [...]
  }
}
```

### `contact-endpoint.php`

**Método:** POST

**Content-Type:** `application/json` ou `text/plain;charset=UTF-8`

**Payload esperado:**

```json
{
  "lead_id": 123,
  "event_id": "contact_1737288000000_xyz789",
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+353871234567",
  "phone_digits": "353871234567",
  "service_type": "deep_cleaning",
  "fbp": "fb.1.1737288000.1234567890",
  "fbc": "fb.1.1737288000.fbclid_value",
  "page_url": "https://barbaracleaning.ie/quote/"
}
```

**Resposta de sucesso:**

```json
{
  "ok": true,
  "lead_id": 123,
  "capi": {
    "ok": true,
    "results": [...]
  }
}
```

---

## 5. Biblioteca CAPI (`lib/bc-capi.php`)

Esta biblioteca contém as funções para enviar eventos para a Meta Conversions API.

### Funções principais:

- **`bc_send_capi_event(PDO $pdo, array $evt)`**: Envia um evento para todos os pixels ativos.
- **`bc_build_user_data(array $user)`**: Constrói o objeto `user_data` com os dados hasheados.
- **`bc_hash_field(?string $v, string $mode)`**: Hasheia um campo de acordo com o tipo (email, phone, etc.).
- **`bc_normalize_ie_phone(string $raw)`**: Normaliza um número de telefone irlandês para o formato E.164.
- **`bc_get_client_ip()`**: Obtém o IP real do cliente, considerando proxies.

### Exemplo de uso:

```php
require_once __DIR__ . '/lib/bc-capi.php';

$result = bc_send_capi_event($pdo, [
  'event_name' => 'Lead',
  'event_time' => time(),
  'event_id' => 'lead_123456',
  'event_source_url' => 'https://barbaracleaning.ie/quote/',
  'action_source' => 'website',
  'user' => [
    'email' => 'john@example.com',
    'phone_digits' => '353871234567',
    'name' => 'John Doe',
    'fbp' => 'fb.1.1737288000.1234567890',
    'fbc' => 'fb.1.1737288000.fbclid_value',
    'client_ip' => '1.2.3.4',
    'client_user_agent' => 'Mozilla/5.0...',
    'zip' => 'D02 X285',
    'city' => 'Dublin',
    'county' => 'Dublin',
    'country' => 'ie'
  ],
  'custom' => [
    'currency' => 'EUR',
    'value' => 100.00
  ]
]);
```

---

## 6. Sistema de Autenticação

O arquivo `admin-auth.php` implementa um sistema simples de autenticação por senha para proteger os dashboards.

- A senha é definida em `_private/admin-config.php`.
- A sessão é armazenada em `$_SESSION['bc_admin']`.
- Para fazer logout, acesse qualquer dashboard com `?logout=1`.

---

## 7. Tracking Loader

O `tracking-loader.php` é um script JavaScript dinâmico que:

1. Busca os pixels ativos na tabela `bc_pixels`.
2. Busca as configurações do Google Ads na tabela `bc_google_ads`.
3. Inicializa o `fbq` (Meta Pixel) e o `gtag` (Google Ads).
4. Implementa um sistema de "Pixel Guardian" que tenta recarregar o pixel caso ele falhe.
5. Implementa "auto-recovery" que reativa automaticamente o último pixel se nenhum estiver ativo.

Para usar, adicione no header do site:

```html
<script>
(function () {
  if (window.__bcTLInjected) return;
  window.__bcTLInjected = true;
  var s = document.createElement('script');
  s.async = true;
  s.src = '/dashboard/tracking-loader.php?ts=' + Date.now();
  document.head.appendChild(s);
})();
</script>
```

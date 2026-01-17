# Barbara Cleaning — Tracking + Leads Dashboard (Pixels + CAPI)

Este repositório contém o **dashboard de Leads/Pixels**, os **endpoints** (Lead/Contact) e o **tracking-loader** que conectam:
**Site (WordPress/Elementor) → Endpoints PHP → MySQL → Dashboards + CAPI (Meta/Google)**

---

## Visão geral do funil (passo a passo)

1) **Anúncio (Meta/Google/YouTube)**
- Usuário clica no criativo (Book Now / Get a Quote).

2) **Landing page /quote (WordPress + Elementor)**
- Vídeo + CTA (Start my quote / Get a quote).
- Header/HTML injeta scripts do `tracking-loader.php` (pixel + coleta de dados e UTMs).

3) **Formulário multi-step (HTML no Elementor)**
- Usuário preenche dados (nome, telefone, email, tipo de serviço, quartos/banheiros etc).

4) **Lead (evento “Lead”)**
- O formulário envia um POST para:
  - `/dashboard/lead-endpoint.php`
- O endpoint:
  - valida campos obrigatórios,
  - salva no MySQL (`bc_leads`),
  - registra meta (UTMs, page_url, user_agent, etc),
  - pode disparar CAPI (via `lib/bc-capi.php`), dependendo da configuração.

5) **WhatsApp (evento “Contact/Went_WhatsApp”)**
- Após enviar o formulário, o usuário clica no botão que abre WhatsApp.
- Esse clique dispara:
  - `/dashboard/contact-endpoint.php`
- Isso marca/relaciona o lead no banco e pode disparar CAPI/Ads.

6) **Schedule / Purchase (pipeline interno)**
- No dashboard você marca o lead como “Scheduled” e depois “Purchase” (quando pago).
- Isso permite separar curiosos de clientes reais e evoluir campanhas para “Schedule/Purchase”.

---

## O que roda onde (GitHub vs Hostinger)

### GitHub (código versionado)
- Aqui fica **todo o código** do dashboard e endpoints.
- **Não sobe segredos** (senha do painel, senha do banco, tokens).

### Hostinger (produção)
Pasta sugerida:
- `/public_html/dashboard/`  ← aqui fica o código publicado

Pastas/arquivos **que existem no servidor**, mas **não devem ser commitados**:
- `/public_html/dashboard/_private/`
  - `db-config.php` (credenciais MySQL)
  - `admin-config.php` (senha do painel)
  - `pixel_config.json` (se aplicável)
- `/public_html/dashboard/_logs/`
  - logs (`*.log`)

> Dica: mantenha no GitHub apenas `*_example.*` e um `.gitkeep` para manter as pastas.

---

## Estrutura de pastas

- `index.php`  
  Página inicial do dashboard (menu/links).

- `leads-dashboard.php`  
  Lista e gerencia leads (dados vêm do `bc_leads`).

- `pixels-dashboard.php`  
  Gerencia pixels/tokens e estado ativo (dados vêm do `bc_pixels`).

- `google-ads-dashboard.php`  
  Painel/visualização para integração e eventos de Ads (dados vêm do `bc_google_ads`).

- `lead-endpoint.php`  
  Endpoint que recebe e grava lead no banco + pode disparar CAPI.

- `contact-endpoint.php`  
  Endpoint para registrar clique/contato (WhatsApp) e disparar evento.

- `tracking-loader.php`  
  Loader que injeta tracking, coleta UTMs e dispara eventos (browser-side).

- `lib/bc-capi.php`  
  Envio server-side para Meta CAPI (quando habilitado/configurado).

- `sql/`  
  Schemas/estrutura das tabelas (structure only).

- `_private/`  
  **Somente exemplos** no GitHub (`*.example.php`, `.gitkeep`).  
  **Arquivos reais** ficam no servidor.

- `_logs/`  
  `.gitkeep` no GitHub. Logs reais apenas no servidor.

---

## Banco de dados (MySQL)

Tabelas principais:
- `bc_leads` → armazena leads e status (lead/contact/schedule/purchase).
- `bc_pixels` → armazena pixels e tokens (ativo/inativo, auto-recovery, etc).
- `bc_google_ads` → eventos/config relacionado a Google Ads (se usado).

### Importar schema (primeira instalação)
No phpMyAdmin, no banco correto:
- Importar os arquivos de `sql/` (CREATE TABLE / structure only).

### Diagnóstico rápido
- Total de leads:
```sql
SELECT COUNT(*) AS total FROM bc_leads;
```

- Últimos leads:
```sql
SELECT id, created_at, name, phone, email
FROM bc_leads
ORDER BY id DESC
LIMIT 10;
```

---

## Formato esperado pelo lead-endpoint (JSON)

O endpoint geralmente espera payload assim:
```json
{
  "event_id": "test_1730000000000",
  "data": {
    "name": "TEST LEAD",
    "phone": "+353871234567",
    "email": "test@example.com",
    "service_type": "deep_cleaning",
    "bedrooms": "2",
    "bathrooms": "1",
    "country": "IE"
  },
  "meta": {
    "page_url": "https://barbaracleaning.com/quote/",
    "referrer": "",
    "user_agent": "Mozilla/5.0 ...",
    "language": "en-US",
    "timezone_offset": -60,
    "screen_width": 1920,
    "screen_height": 1080
  }
}
```

> Se você mandar `{name, phone, email}` direto na raiz, ele pode retornar `missing_required_fields`.

---

## Como testar (sem depender do formulário)

### 1) Teste via Console do navegador
Abra o site, F12 → Console e execute:
```js
fetch('/dashboard/lead-endpoint.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    event_id: 'test_' + Date.now(),
    data: {
      name: 'TEST LEAD',
      phone: '+353871234567',
      email: 'test@example.com',
      service_type: 'deep_cleaning',
      bedrooms: '2',
      bathrooms: '1',
      country: 'IE'
    },
    meta: {
      page_url: location.href,
      referrer: document.referrer,
      user_agent: navigator.userAgent,
      language: navigator.language,
      timezone_offset: new Date().getTimezoneOffset(),
      screen_width: screen.width,
      screen_height: screen.height
    }
  })
}).then(r => r.text()).then(console.log).catch(console.error);
```

### 2) Confirme no MySQL
Depois do teste, rode:
```sql
SELECT id, created_at, name, phone, email
FROM bc_leads
ORDER BY id DESC
LIMIT 5;
```

---

## Erros comuns e solução rápida

### `missing_required_fields`
- Payload do JS não está no formato esperado (use `data` + `meta`).

### `db_insert_failed` (500)
- O endpoint tentou inserir no MySQL e falhou por um destes motivos:
  - credenciais erradas em `_private/db-config.php`,
  - tabela/coluna faltando,
  - coluna NOT NULL sem default,
  - permissão do usuário do banco.
- Verifique:
  - `DESCRIBE bc_leads;`
  - credenciais do DB no arquivo `_private/db-config.php`.

---

## Segurança (recomendado)

1) **Não commitar segredos**
- `db-config.php`, `admin-config.php`, `pixel_config.json` ficam fora do Git.
- Use `.gitignore` para bloquear.

2) **Bloquear acesso direto às pastas sensíveis**
- `_private` e `_logs` devem ter `.htaccess` bloqueando acesso web.

3) **Senha forte do dashboard**
- Defina em `_private/admin-config.php`.

---

## Deploy (GitHub → Hostinger)

### Opção A: Upload manual (simples)
1) Baixe o ZIP do GitHub.
2) Extraia e envie tudo para:
   - `/public_html/dashboard/`
3) Crie os arquivos reais:
   - `/public_html/dashboard/_private/db-config.php`
   - `/public_html/dashboard/_private/admin-config.php`
   - (opcional) `/public_html/dashboard/_private/pixel_config.json`
4) Crie `/public_html/dashboard/_logs/` no servidor.

### Opção B: Git Deploy (automático)
1) Configure o Git Deploy no hPanel.
2) Defina o caminho de instalação como `dashboard` (para cair em `/public_html/dashboard/`).
3) Mantenha o destino vazio antes do primeiro deploy.
4) Depois, só dar push no GitHub e o Hostinger atualiza.

---

## Conexão com Elementor / WordPress

- O header/snippet do Elementor deve chamar os endpoints sempre assim:
  - `/dashboard/tracking-loader.php`
  - `/dashboard/lead-endpoint.php`
  - `/dashboard/contact-endpoint.php`

> Se mudar o nome da pasta no servidor, atualize esses paths no Elementor.

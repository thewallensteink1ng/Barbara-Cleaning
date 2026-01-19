# Contexto Rápido para IAs

Este arquivo contém um resumo executivo do sistema para que qualquer IA possa entender rapidamente a estrutura e ajudar na manutenção.

---

## Resumo do Sistema

**Barbara Cleaning** é um sistema de captação de leads para uma empresa de limpeza na Irlanda. O funil funciona assim:

1. **Anúncio** (Meta/Google Ads) → Usuário clica
2. **Site** (WordPress) → Usuário navega
3. **Formulário** (/quote) → Usuário preenche dados → Dispara evento **Lead**
4. **WhatsApp** (botão) → Usuário clica → Dispara evento **Contact**
5. **Dashboard** → Admin marca **Schedule** (agendamento) com valor
6. **Dashboard** → Admin marca **Purchase** (compra) com valor final

---

## Arquivos Críticos

| Arquivo | Função | Problema Comum |
| :--- | :--- | :--- |
| `lead-endpoint.php` | Recebe dados do formulário e salva no banco | **ATUALMENTE COM BUG**: contém código do `eircode-lookup.php` |
| `contact-endpoint.php` | Recebe clique do WhatsApp e atualiza lead | Funciona corretamente |
| `leads-dashboard.php` | Interface de gerenciamento de leads | Funciona corretamente |
| `lib/bc-capi.php` | Envia eventos para Meta CAPI | Funciona corretamente |
| `tracking-loader.php` | Carrega pixels e gtag dinamicamente | Funciona corretamente |

---

## Banco de Dados

**Host:** Hostinger (MySQL)
**Database:** `u278078154_h4Hok`

### Tabelas:
- `bc_leads` - Armazena todos os leads
- `bc_pixels` - Configurações do Meta Pixel
- `bc_google_ads` - Configurações do Google Ads

---

## Eventos de Rastreamento

| Evento | Quando dispara | Frontend | Backend | Valor |
| :--- | :--- | :--- | :--- | :--- |
| `InitiateCheckout` | Clica para ir ao formulário | ✅ | ❌ | Não |
| `ViewContent` | Formulário carrega | ✅ | ❌ | Não |
| `Lead` | Completa o formulário | ✅ | ✅ (CAPI) | Não |
| `Contact` | Clica no WhatsApp | ✅ | ✅ (CAPI) | Não |
| `Schedule` | Admin marca no dashboard | ❌ | ✅ (CAPI) | Sim (manual) |
| `Purchase` | Admin marca no dashboard | ❌ | ✅ (CAPI) | Sim (manual) |

---

## Estrutura de Diretórios

```
/dashboard/
├── _private/           # Configs sensíveis (NÃO versionar)
│   ├── db-config.php   # Credenciais do banco
│   └── admin-config.php # Senha do dashboard
├── lib/
│   └── bc-capi.php     # Biblioteca Meta CAPI
├── sql/                # Schemas das tabelas
├── lead-endpoint.php   # Endpoint Lead (A CORRIGIR)
├── contact-endpoint.php # Endpoint Contact
├── leads-dashboard.php # Dashboard principal
├── pixels-dashboard.php # Config pixels
├── google-ads-dashboard.php # Config Google Ads
└── tracking-loader.php # Carregador de scripts
```

---

## Payload do Lead (Frontend → Backend)

```json
{
  "source": "cleaning_quote_form",
  "timestamp": "2025-01-19T12:00:00.000Z",
  "event_id": "lead_1737288000000_abc123",
  "data": {
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
    "page_url": "https://site.com/quote/",
    "fbp": "fb.1.xxx.yyy",
    "fbc": "fb.1.xxx.fbclid",
    "gclid": "...",
    "utm_source": "google",
    "utm_medium": "cpc"
  }
}
```

---

## Resposta Esperada do `lead-endpoint.php`

```json
{
  "ok": true,
  "id": 123,
  "capi": {
    "ok": true,
    "results": [{"pixel_id": "xxx", "ok": true}]
  }
}
```

---

## Problema Atual

**O arquivo `lead-endpoint.php` está com o código errado.** Ele contém o código do `eircode-lookup.php` (busca de endereço por CEP) em vez do código que deveria:

1. Receber o payload JSON do formulário
2. Validar os dados
3. Inserir na tabela `bc_leads`
4. Enviar evento Lead para a CAPI do Meta
5. Retornar o ID do lead criado

**Solução:** Criar o código correto do `lead-endpoint.php` baseado no `contact-endpoint.php` e na estrutura da tabela `bc_leads`.

---

## Checklist para Correções

Antes de fazer qualquer alteração:

- [ ] Entender o fluxo completo (este documento)
- [ ] Verificar a estrutura da tabela `bc_leads` (ver `sql/schema_bc_leads.sql`)
- [ ] Verificar como o `contact-endpoint.php` funciona (usar como referência)
- [ ] Verificar como o `lib/bc-capi.php` funciona
- [ ] Testar localmente antes de fazer deploy
- [ ] Não alterar o layout dos dashboards
- [ ] Não quebrar funcionalidades existentes

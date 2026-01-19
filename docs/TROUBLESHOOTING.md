# Guia de Resolução de Problemas

Este documento lista os problemas mais comuns e suas soluções.

---

## 1. Leads não aparecem no Dashboard

### Sintomas
- O formulário é preenchido, mas o lead não aparece no `leads-dashboard.php`.
- O evento `Lead` é disparado no navegador (visível no Pixel Helper), mas não chega ao servidor.

### Causas possíveis

| Causa | Como verificar | Solução |
| :--- | :--- | :--- |
| **`lead-endpoint.php` com código errado** | Abra o arquivo e verifique se o conteúdo é sobre "Eircode Lookup" em vez de "Lead Endpoint". | Substitua o arquivo pelo código correto (veja seção abaixo). |
| **Erro de conexão com o banco de dados** | Verifique os logs de erro do PHP ou acesse `/dashboard/lead-endpoint.php` diretamente no navegador. | Corrija as credenciais em `_private/db-config.php`. |
| **Tabela `bc_leads` não existe** | Acesse o phpMyAdmin e verifique se a tabela existe. | Execute o script `sql/schema_bc_leads.sql` no banco de dados. |
| **Erro de CORS** | Abra o console do navegador (F12) e procure por erros de CORS. | O endpoint já deve ter os headers CORS configurados. Verifique se o domínio está correto. |
| **Hostinger bloqueando requisições** | Verifique se há erros 403 ou 500 no console do navegador. | Verifique as configurações de segurança da Hostinger e o `.htaccess`. |

### Código correto do `lead-endpoint.php`

Se o arquivo estiver com o código errado, substitua pelo código correto que será fornecido na correção.

---

## 2. Eventos CAPI não estão sendo enviados

### Sintomas
- Os leads aparecem no dashboard, mas a coluna "Lead Sent" mostra "No".
- O Meta Events Manager não mostra eventos de servidor.

### Causas possíveis

| Causa | Como verificar | Solução |
| :--- | :--- | :--- |
| **Pixel não está ativo** | Acesse `pixels-dashboard.php` e verifique se há um pixel ativo. | Ative um pixel clicando em "Ativar". |
| **Access Token inválido ou expirado** | Verifique o log em `_private/pixel_actions.log`. | Gere um novo token no Meta Business Suite e atualize no dashboard. |
| **Erro na função `bc_send_capi_event`** | Verifique o log em `_private/pixel_actions.log`. | Corrija o erro indicado no log. |

---

## 3. Evento Contact não está sendo disparado

### Sintomas
- O usuário clica no botão do WhatsApp, mas o evento `Contact` não é registrado.
- A coluna `went_whatsapp` continua como 0 no banco de dados.

### Causas possíveis

| Causa | Como verificar | Solução |
| :--- | :--- | :--- |
| **`lead_id` não foi salvo** | Verifique se o `lead-endpoint.php` está retornando o `id` corretamente. | Corrija o `lead-endpoint.php`. |
| **Erro no `contact-endpoint.php`** | Acesse o endpoint diretamente e verifique a resposta. | Verifique os logs de erro do PHP. |
| **Navegação muito rápida** | O usuário pode ter clicado no WhatsApp antes da requisição de lead terminar. | O código já usa `sendBeacon` para mitigar isso. |

---

## 4. Dashboard não carrega / Erro 500

### Sintomas
- Ao acessar qualquer página do dashboard, aparece um erro 500 ou página em branco.

### Causas possíveis

| Causa | Como verificar | Solução |
| :--- | :--- | :--- |
| **Arquivo `db-config.php` ausente** | Verifique se o arquivo existe em `_private/`. | Crie o arquivo com as credenciais corretas. |
| **Credenciais do banco incorretas** | Verifique os valores em `db-config.php`. | Corrija as credenciais. |
| **Erro de sintaxe PHP** | Verifique os logs de erro do PHP. | Corrija o erro de sintaxe indicado. |

---

## 5. Pixel não está carregando no site

### Sintomas
- O Meta Pixel Helper mostra que o pixel não está instalado.
- O `tracking-loader.php` retorna erro.

### Causas possíveis

| Causa | Como verificar | Solução |
| :--- | :--- | :--- |
| **Script não está no header** | Verifique o código-fonte da página. | Adicione o script do `tracking-loader.php` no header. |
| **Erro no `tracking-loader.php`** | Acesse `/dashboard/tracking-loader.php` diretamente no navegador. | Verifique a mensagem de erro e corrija. |
| **WordPress não carregando** | O `tracking-loader.php` usa `$wpdb` do WordPress. | Verifique se o caminho para `wp-load.php` está correto. |

---

## 6. Google Ads conversões não estão sendo rastreadas

### Sintomas
- As conversões não aparecem no Google Ads.
- O `gtag` não está sendo carregado.

### Causas possíveis

| Causa | Como verificar | Solução |
| :--- | :--- | :--- |
| **Tag não está ativa** | Acesse `google-ads-dashboard.php` e verifique. | Ative a tag clicando em "Ativar". |
| **Labels incorretos** | Verifique se os labels correspondem aos do Google Ads. | Corrija os labels no dashboard. |
| **Conversion ID incorreto** | Verifique se o formato é `AW-XXXXXXXXX`. | Corrija o Conversion ID. |

---

## 7. Como testar o sistema

### Teste do `lead-endpoint.php`

Execute no console do navegador (F12):

```javascript
fetch('/dashboard/lead-endpoint.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    event_id: 'test_lead_' + Date.now(),
    data: {
      name: 'TESTE LEAD',
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
      user_agent: navigator.userAgent
    }
  })
}).then(r => r.json()).then(console.log).catch(console.error);
```

**Resposta esperada:**
```json
{"ok": true, "id": 123, "capi": {"ok": true, ...}}
```

### Teste do `contact-endpoint.php`

```javascript
fetch('/dashboard/contact-endpoint.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    lead_id: 123, // Use o ID retornado pelo teste anterior
    event_id: 'test_contact_' + Date.now(),
    name: 'TESTE LEAD',
    phone: '+353871234567',
    email: 'test@example.com',
    service_type: 'deep_cleaning'
  })
}).then(r => r.json()).then(console.log).catch(console.error);
```

### Verificar conectividade

Acesse no navegador:
- `/dashboard/ping.txt` → Deve mostrar "OK"
- `/dashboard/tracking-loader.php` → Deve retornar código JavaScript

---

## 8. Logs úteis

| Arquivo | Localização | Conteúdo |
| :--- | :--- | :--- |
| `pixel_actions.log` | `_private/pixel_actions.log` | Ações do pixel (ativação, desativação, erros CAPI) |
| `tracking-loader-errors.log` | `_logs/tracking-loader-errors.log` | Erros do tracking-loader.php |
| Logs do PHP | Configurado no servidor | Erros gerais do PHP |

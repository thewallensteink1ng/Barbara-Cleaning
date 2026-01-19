# Barbara Cleaning - Documentação do Sistema de Leads

## 1. Visão Geral do Projeto

Este documento detalha la arquitetura e o funcionamento do sistema de captação e gerenciamento de leads para a **Barbara Cleaning**. O objetivo é centralizar as informações para facilitar a manutenção, a integração de novos desenvolvedores (ou IAs) e a resolução de problemas.

O sistema é construído com um frontend em HTML/JavaScript (integrado ao Elementor no WordPress) e um backend em PHP com um banco de dados MySQL. Ele gerencia um funil de marketing digital completo, desde o clique no anúncio até a conversão final (compra), rastreando cada etapa com as APIs de conversão do Meta (Facebook/Instagram) e do Google Ads.

---

## 2. Arquitetura do Sistema

O sistema é dividido em três componentes principais:

| Componente | Tecnologia | Descrição |
| :--- | :--- | :--- |
| **Frontend** | HTML, CSS, JavaScript | Um formulário de múltiplos passos inserido em uma página do WordPress (via Elementor). É responsável pela coleta de dados do usuário e pelo disparo de eventos de rastreamento no navegador. |
| **Backend** | PHP | Um conjunto de scripts localizados no diretório `/dashboard/`. Responsável por receber os dados do frontend, salvar no banco de dados e enviar eventos para as APIs de conversão (CAPI). |
| **Banco de Dados** | MySQL | Armazena todos os dados de leads, configurações de pixels do Meta e tags do Google Ads. |

### Estrutura do Banco de Dados

O banco de dados `u278078154_h4Hok` contém três tabelas principais:

- **`bc_leads`**: Tabela central que armazena todas as informações de cada lead, incluindo dados de contato, detalhes do serviço, informações de rastreamento (UTMs, GCLID, etc.) e o estágio do funil (`lead`, `contact`, `schedule`, `purchase`).
- **`bc_pixels`**: Gerencia as configurações dos Pixels do Meta. Permite adicionar múltiplos pixels e tokens de acesso, mas apenas um fica ativo por vez para o envio de eventos via CAPI.
- **`bc_google_ads`**: Gerencia as configurações de conversão do Google Ads. Armazena o Conversion ID (AW-XXXX) e os `labels` de conversão para cada etapa do funil.

---

## 3. Fluxo do Funil de Leads

O funil é projetado para rastrear o usuário desde o primeiro contato até a compra final.

| Etapa | Ação do Usuário | Evento Disparado | Script Frontend | Script Backend |
| :--- | :--- | :--- | :--- | :--- |
| 1. **Início do Orçamento** | Clica no botão para ir à página do formulário. | `InitiateCheckout` (Meta) | `Form Script.txt` | N/A |
| 2. **Visualização do Formulário** | A página do formulário é carregada. | `ViewContent` (Meta) | `Form Completo, site.txt` | N/A |
| 3. **Envio do Formulário** | Preenche os dados e clica em "Último Passo". | `Lead` (Meta & Google) | `trackLead()` | `lead-endpoint.php` |
| 4. **Contato via WhatsApp** | Clica no botão "Chat on WhatsApp". | `Contact` (Meta & Google) | `trackContact()` | `contact-endpoint.php` |
| 5. **Agendamento** | Admin marca o lead como agendado no dashboard. | `Schedule` (Meta) | N/A | `leads-dashboard.php` |
| 6. **Compra** | Admin marca o lead como compra finalizada no dashboard. | `Purchase` (Meta) | N/A | `leads-dashboard.php` |

### Detalhes do Fluxo

1.  **Rastreamento Inicial**: Quando a página do site carrega, o script `tracking-loader.php` é chamado. Ele busca no banco de dados (`bc_pixels` e `bc_google_ads`) as configurações ativas do Meta Pixel e do Google Ads e as injeta na página, preparando o ambiente para o rastreamento.

2.  **Envio do Lead**: Ao completar o formulário, a função JavaScript `trackLead()` coleta todos os dados do formulário e metadados de rastreamento (UTMs, cookies `_fbp` e `_fbc`, etc.). Ela envia um evento `Lead` para o navegador (Meta e Google) e, em seguida, envia todos os dados via POST para o `lead-endpoint.php`. Este script backend é responsável por salvar o lead no banco de dados e enviar um evento `Lead` correspondente para a CAPI do Meta, garantindo a deduplicação com o evento do navegador.

3.  **Clique no WhatsApp**: Após o envio, o usuário vê um botão para o WhatsApp. Ao clicar, a função `trackContact()` é acionada. Ela envia um evento `Contact` para o navegador e faz uma requisição para o `contact-endpoint.php`, que atualiza o status do lead no banco de dados (marcando `went_whatsapp = 1`) e envia o evento `Contact` para a CAPI do Meta.

4.  **Gerenciamento no Dashboard**: O administrador acessa o `leads-dashboard.php` (protegido por senha) para visualizar e gerenciar os leads. A partir dali, ele pode atualizar o estágio do funil para `Schedule` ou `Purchase`, o que dispara os respectivos eventos para a CAPI do Meta.

---

## 4. Detalhamento dos Arquivos

### Arquivos Principais

- **`Form Completo, site.txt`**: O código HTML e JavaScript do formulário de múltiplos passos. Contém toda a lógica de validação, coleta de dados e disparo de eventos de rastreamento do lado do cliente (`trackLead`, `trackContact`).

- **`lead-endpoint.php`**: **(Arquivo a ser corrigido)**. Endpoint que recebe os dados do formulário. Sua função é: 
    1. Ler o payload JSON enviado pelo frontend.
    2. Validar os dados recebidos.
    3. Inserir um novo registro na tabela `bc_leads`.
    4. Enviar o evento `Lead` para a CAPI do Meta usando a função `bc_send_capi_event()`.
    5. Retornar o `id` do lead recém-criado para o frontend.

- **`contact-endpoint.php`**: Endpoint que recebe o clique no botão do WhatsApp. Ele atualiza o lead existente (setando `went_whatsapp = 1`) e envia o evento `Contact` para a CAPI.

- **`leads-dashboard.php`**: A interface principal de gerenciamento de leads. Permite buscar, filtrar e atualizar o estágio de cada lead, disparando os eventos `Schedule` e `Purchase`.

- **`lib/bc-capi.php`**: Biblioteca central para comunicação com a CAPI do Meta. Contém a função `bc_send_capi_event()`, que monta o payload do evento, hasheia os dados do usuário e o envia para a API do Facebook para todos os pixels ativos.

- **`tracking-loader.php`**: Script dinâmico que carrega as configurações de rastreamento (Pixel IDs e Google Conversion ID) do banco de dados e as disponibiliza para o JavaScript do frontend.

### Arquivos de Configuração (Diretório `_private`)

Estes arquivos contêm informações sensíveis e **não devem** ser versionados no Git.

- **`db-config.php`**: Contém as credenciais de acesso ao banco de dados MySQL.
- **`admin-config.php`**: Define a senha de acesso ao dashboard.
- **`pixel_config.json`**: Configurações adicionais para o `tracking-loader.php`, como a funcionalidade de auto-reativação de pixels.

---

## 5. Como Usar este Repositório

1.  **Clone o Repositório**: `gh repo clone <seu-usuario>/Barbara-Cleaning-v2`
2.  **Configuração do Ambiente**: 
    - No servidor, crie o diretório `/dashboard/_private/`.
    - Crie os arquivos `db-config.php` e `admin-config.php` dentro de `_private/` com as credenciais corretas.
3.  **Deploy**: Faça o deploy dos arquivos para o diretório `/dashboard/` na sua hospedagem. O versionamento com Git já está configurado para facilitar este processo.
4.  **Manutenção**: Para futuras alterações, edite os arquivos localmente, faça o commit e o push para o GitHub, e depois puxe (pull) as alterações no servidor.

# Semyra — Identidade Visual Oficial

> Documento de referência visual para a interface, marca e comunicação do Semyra.
>
> Direção aprovada: **moderna e minimalista**, com base escura, atmosfera cinematográfica e uso controlado de gradiente neon azul → roxo → magenta.

---

## 1. Visão geral da marca

O **Semyra** é uma plataforma para assistir conteúdos junto com outras pessoas à distância, com foco em transmissão compartilhada, sincronização e experiência de sala.

A identidade visual deve transmitir, simultaneamente:

- **conexão entre pessoas**;
- **sincronização em tempo real**;
- **transmissão e vídeo**;
- **imersão cinematográfica**;
- **tecnologia acessível**;
- **simplicidade de uso**.

O Semyra não deve parecer apenas mais um aplicativo de streaming. A experiência visual precisa reforçar a ideia de que o conteúdo está sendo **vivido em conjunto**.

A marca deve ser percebida como:

- moderna;
- limpa;
- escura;
- cinematográfica;
- social sem parecer uma rede social;
- tecnológica sem parecer técnica demais;
- premium sem ser excessivamente sofisticada;
- simples e direta.

---

## 2. Conceito visual principal

### 2.1 Conceito

A direção visual oficial pode ser resumida como:

**Semyra — Cinema Social**

ou, internamente:

**Semyra — Neon Cinema**

A ideia é manter aproximadamente:

- **90% de interface escura e cinematográfica**;
- **10% de luz, cor e destaque da marca**.

O gradiente neon não deve dominar a interface. Ele funciona como assinatura visual e ponto de energia.

---

## 3. Logo

A logo escolhida combina três conceitos principais:

1. **Tela / player de vídeo** — representado pelo contorno arredondado.
2. **Pessoas assistindo juntas** — representadas pelas silhuetas centrais.
3. **Play / transmissão** — representado pelo triângulo lateral.

Essa composição comunica de forma direta a essência do Semyra: **pessoas reunidas em torno de uma transmissão**.

### 3.1 Estrutura

A marca pode ser usada em três formatos:

#### Marca completa

Ícone + nome `Semyra`.

Uso recomendado:

- home;
- cabeçalhos;
- telas institucionais;
- páginas de entrada;
- materiais de divulgação.

#### Ícone isolado

Somente o símbolo com pessoas + tela + play.

Uso recomendado:

- favicon;
- PWA;
- ícone de aplicativo;
- miniaturas;
- avatar do sistema;
- estados vazios;
- marca d'água discreta.

#### Wordmark

Somente `Semyra`.

Uso recomendado quando o ícone já estiver presente no contexto.

### 3.2 Área de respiro

Nunca encostar a logo diretamente em bordas ou elementos.

Como regra geral, usar margem mínima equivalente a aproximadamente **25% da altura do símbolo**.

### 3.3 O que evitar

Não:

- distorcer horizontal ou verticalmente;
- inverter o gradiente sem necessidade;
- trocar o gradiente por cores aleatórias;
- aplicar contornos grossos;
- usar sobre fundos com contraste insuficiente;
- adicionar sombras exageradas;
- alterar a proporção entre ícone e wordmark;
- utilizar glow excessivo.

---

## 4. Paleta de cores

A interface oficial é baseada em tons escuros de azul e preto, com pontos de luz em azul, roxo e magenta.

### 4.1 Cores de base

| Token | Cor | Uso |
|---|---:|---|
| `--color-bg` | `#050811` | fundo principal |
| `--color-bg-soft` | `#0A1020` | fundos secundários |
| `--color-surface` | `#101827` | cards, modais e superfícies |
| `--color-surface-hover` | `#141F31` | hover de superfícies |
| `--color-border` | `#1C2A3D` | bordas discretas |
| `--color-border-strong` | `#273851` | bordas de maior contraste |

### 4.2 Cores de texto

| Token | Cor | Uso |
|---|---:|---|
| `--color-text` | `#F7F9FC` | texto principal |
| `--color-text-soft` | `#D5DCE8` | texto secundário de maior importância |
| `--color-text-muted` | `#95A3B8` | legendas e descrições |
| `--color-text-disabled` | `#58657A` | estados desabilitados |

### 4.3 Cores da marca

| Token | Cor | Uso |
|---|---:|---|
| `--color-cyan` | `#16C7FF` | início do gradiente, sincronização |
| `--color-blue` | `#2864FF` | ações e estados ativos |
| `--color-purple` | `#7B3CFF` | transição e destaque |
| `--color-magenta` | `#F21BCB` | energia, transmissão e final do gradiente |

### 4.4 Gradiente principal

```css
--gradient-brand: linear-gradient(
    120deg,
    #16C7FF 0%,
    #2864FF 35%,
    #7B3CFF 65%,
    #F21BCB 100%
);
```

Uso recomendado:

- botão principal;
- logo;
- indicador ativo;
- estados de transmissão;
- detalhe de progresso;
- bordas especiais;
- foco de marca.

Evitar utilizar o gradiente em grandes blocos de texto ou em muitos componentes simultaneamente.

---

## 5. Estados semânticos

As cores da interface também devem comunicar estado.

### 5.1 Sincronizado

Cor principal: cyan / azul.

Exemplo:

`● Sincronizado`

Uso:

- player sincronizado;
- conexão normal;
- sucesso de atualização de estado.

### 5.2 Transmissão ativa

Cor principal: magenta ou vermelho controlado.

Exemplo:

`● AO VIVO`

Uso:

- transmissor ativo;
- live em execução;
- transmissão principal.

### 5.3 Reconectando

Cor principal: roxo.

Exemplo:

`◌ Reconectando…`

### 5.4 Inativo

Cor principal: cinza azulado.

Exemplo:

`Nenhuma transmissão ativa`

---

## 6. Tipografia

A tipografia deve ser moderna, legível e simples.

### 6.1 Família principal

Sugestão preferencial:

- **Sora** — títulos e destaques;
- **Inter** — interface e textos.

Fallback recomendado:

```css
font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
```

### 6.2 Hierarquia

#### Display / Hero

Uso: título principal da home.

- peso: 700–800;
- grande contraste;
- espaçamento compacto.

Exemplo:

`Semyra`

#### Título de seção

- peso: 600–700;
- tamanho médio;
- cor principal.

#### Texto comum

- peso: 400–500;
- alta legibilidade;
- linhas relativamente curtas.

#### Microtexto

Usar para:

- labels;
- status;
- metadados;
- legendas.

Preferência por uppercase apenas quando houver forte função de rótulo.

Exemplo:

`ASSISTA JUNTO.`

---

## 7. Espaçamento e ritmo

O Semyra deve manter uma interface respirada, principalmente fora da sala de vídeo.

Escala sugerida:

```css
--space-1: 4px;
--space-2: 8px;
--space-3: 12px;
--space-4: 16px;
--space-5: 20px;
--space-6: 24px;
--space-8: 32px;
--space-10: 40px;
--space-12: 48px;
--space-16: 64px;
```

Princípios:

- evitar interface apertada;
- preservar espaços vazios;
- agrupar elementos relacionados;
- separar claramente conteúdo, controles e metadados.

---

## 8. Bordas e formas

A geometria principal da marca é baseada em cantos arredondados.

### 8.1 Escala de radius

```css
--radius-sm: 8px;
--radius-md: 12px;
--radius-lg: 18px;
--radius-xl: 26px;
--radius-pill: 999px;
```

### 8.2 Uso

- botões: `12px–18px`;
- cards: `18px–26px`;
- modais: `18px–26px`;
- chips: pill;
- controles do player: arredondados e compactos.

Evitar cantos completamente quadrados.

---

## 9. Sombras, glow e profundidade

O Semyra deve ter profundidade, mas sem excesso.

### 9.1 Sombra padrão

```css
box-shadow: 0 12px 40px rgba(0, 0, 0, 0.28);
```

### 9.2 Glow de marca

Usar apenas em elementos especiais.

```css
box-shadow:
    0 0 20px rgba(40, 100, 255, 0.25),
    0 0 35px rgba(242, 27, 203, 0.12);
```

### 9.3 Regra

Glow nunca deve ser padrão para todos os cards.

Reservar para:

- logo;
- botão principal;
- transmissão ativa;
- estado de destaque;
- foco visual da home.

---

## 10. Backgrounds

### 10.1 Fundo principal

Preferir `#050811` ou `#0A1020` em vez de preto puro.

### 10.2 Luz ambiente

Pode existir um gradiente radial muito discreto.

```css
background:
    radial-gradient(
        circle at 50% 0%,
        rgba(40, 100, 255, 0.12),
        transparent 40%
    ),
    #050811;
```

### 10.3 Home

Na home, utilizar gradientes e/ou imagem cinematográfica apenas para reforçar impacto visual.

A imagem nunca deve reduzir legibilidade.

---

## 11. Home — direção oficial

A direção escolhida é **moderna e minimalista**.

A home deve possuir:

- fundo escuro;
- logo clara no topo;
- pequena navegação;
- hero central ou levemente deslocado;
- slogan `ASSISTA JUNTO.`;
- título `Semyra`;
- texto curto;
- botão principal `Criar sala`;
- poucos benefícios visuais na parte inferior.

### 11.1 Hero

Estrutura sugerida:

```text
ASSISTA JUNTO.

Semyra

Crie uma sala e assista com
seus amigos, mesmo à distância.

[ + Criar sala ]
```

### 11.2 Benefícios

Exemplos:

- Sincronização em tempo real;
- Assista com amigos;
- Transmissão via YouTube;
- Simples e rápido.

Os benefícios devem utilizar ícones simples e pouco texto.

---

## 12. Header / Navegação

O header deve ser fino, discreto e funcional.

### Desktop

Elementos possíveis:

- logo;
- `Início`;
- `Como funciona`;
- `Status`;
- botão `Criar sala`;
- menu adicional.

A navegação não deve competir com o conteúdo.

### Mobile

Preferir:

- logo à esquerda;
- menu hamburguer à direita;
- CTA visível quando necessário.

---

## 13. Botões

### 13.1 Primário

Usar gradiente oficial.

Exemplos:

- `Criar sala`;
- `Iniciar transmissão`.

```css
background: var(--gradient-brand);
color: #fff;
border: 0;
border-radius: 14px;
font-weight: 600;
```

### 13.2 Secundário

Superfície escura, borda discreta.

Uso:

- compartilhar;
- participantes;
- opções;
- cancelar.

### 13.3 Icon button

Formato:

- 40–44px;
- fundo translúcido;
- borda fina;
- ícone claro.

---

## 14. Ícones

Direção:

- outline;
- simples;
- arredondados;
- espessura aproximada de `1.75px–2px`.

Ícones principais:

- play;
- pause;
- volume;
- mute;
- fullscreen;
- users;
- share;
- link;
- monitor;
- cast;
- settings;
- menu;
- close.

Ícones preenchidos devem ser reservados para estados ativos ou ações muito importantes.

---

## 15. Cards

Cards devem ser usados com moderação.

Características:

- fundo `#101827`;
- borda `#1C2A3D`;
- radius `18px–26px`;
- sombra leve;
- sem excesso de separações internas.

O Semyra não deve parecer um dashboard corporativo.

---

## 16. Modal de criação de sala

Direção escolhida: simples, escura e centralizada.

Estrutura recomendada:

```text
Criar sala

Cole o link da transmissão do YouTube

[ https://youtube.com/... ]

[ Criar sala ]
```

### 16.1 Visual

- overlay escuro;
- background levemente desfocado;
- modal em `#101827`;
- borda discreta;
- radius grande;
- CTA com gradiente.

### 16.2 Mensagem de apoio

Texto curto e direto.

Exemplo:

`A sala será criada e você já entrará nela.`

---

## 17. Sala — princípio fundamental

A sala é o núcleo do Semyra.

A prioridade absoluta é o conteúdo.

Quando há transmissão ativa:

- vídeo deve dominar toda a tela;
- interface deve desaparecer visualmente;
- controles devem ser mínimos;
- elementos secundários devem ser sobrepostos;
- nada deve competir com a transmissão.

A inspiração de comportamento é uma experiência próxima de cinema / Academia TV.

---

## 18. Sala — controles minimalistas sobre o vídeo

A direção escolhida é de controles discretos sobre o conteúdo.

### 18.1 Topo esquerdo

- logo pequena;
- nome `Semyra`;
- código da sala.

Exemplo:

```text
[logo] Semyra
Sala G433HR8L
```

### 18.2 Topo direito

- participantes;
- compartilhar;
- outros controles essenciais.

Botões com fundo translúcido.

### 18.3 Rodapé

Concentrar reprodução e mídia.

Exemplo:

```text
[Pause]  1:24:35 / ● Ao vivo

────────────●────────────

[volume] [legendas] [fullscreen]
```

### 18.4 Comportamento

Quando o usuário não estiver interagindo, os controles podem diminuir em destaque ou desaparecer, desde que isso não prejudique acessibilidade e uso.

---

## 19. Sala sem transmissão

A direção aprovada é uma tela minimalista e centralizada.

### Estrutura

```text
[logo]

Nenhuma transmissão ativa

Seja o primeiro a iniciar uma transmissão
e assista junto com seus amigos.

[ Iniciar transmissão ]
```

### 19.1 Elementos permitidos

- logo central;
- mensagem curta;
- CTA principal;
- fundo escuro;
- ondas/gradientes discretos nas extremidades.

Evitar textos longos ou componentes extras.

---

## 20. Participantes

Participantes devem ser representados de forma compacta.

Exemplo:

```text
● ● ● +3
```

ou por pequeno grupo de avatares sobrepostos.

### 20.1 Transmissor

Quem está transmitindo pode receber:

- borda gradiente;
- pequeno chip `Transmitindo`;
- status visual especial.

Não utilizar coroa ou símbolo de dono.

O conceito é **transmissor**, não proprietário da sala.

---

## 21. Chips e badges

Exemplos:

- `● AO VIVO`;
- `● Sincronizado`;
- `◌ Reconectando…`;
- `4 pessoas`.

Formato:

- pill;
- fundo discreto;
- borda leve;
- ícone/status pequeno;
- tipografia compacta.

---

## 22. Player

O player deve ser visualmente limpo.

### Princípios

- conteúdo em primeiro plano;
- controles próprios;
- ausência de elementos visuais desnecessários;
- barra de progresso fina;
- volume compacto;
- fullscreen claro;
- feedback imediato.

### Barra de progresso

Pode utilizar o gradiente da marca em intensidade moderada.

---

## 23. Volume

O controle de volume deve seguir a linguagem do player.

Formato recomendado:

- ícone;
- slider fino;
- valor não precisa ficar permanentemente visível;
- feedback visual suave.

O controle deve parecer parte natural do player, não um formulário.

---

## 24. Responsividade

### Desktop

- conteúdo amplo;
- player dominante;
- navegação horizontal;
- controles bem espaçados.

### Tablet

- reduzir espaços laterais;
- manter player protagonista;
- compactar navegação.

### Mobile

- CTA de largura ampla;
- navegação simplificada;
- textos menores;
- player adaptado ao viewport;
- controles em áreas fáceis de tocar;
- botões com mínimo recomendado de 44px.

---

## 25. Motion e animações

Animação deve reforçar estado, não decorar.

Permitido:

- fade suave;
- mudança de opacity;
- pequenas transições de escala;
- brilho controlado;
- deslocamento leve.

Duração sugerida:

```css
--motion-fast: 120ms;
--motion-base: 180ms;
--motion-slow: 280ms;
```

Evitar:

- animações longas;
- bouncing excessivo;
- partículas;
- transições chamativas durante a reprodução.

---

## 26. Acessibilidade

A identidade visual nunca deve comprometer uso.

Regras:

- contraste adequado;
- foco visível;
- controles acessíveis por teclado;
- não depender exclusivamente de cor;
- labels e aria-labels em ícones;
- alvos de toque amplos;
- suporte a `prefers-reduced-motion`.

---

## 27. Linguagem textual

O Semyra deve falar de maneira simples, amigável e direta.

Preferir:

- `Criar sala`;
- `Iniciar transmissão`;
- `Compartilhar sala`;
- `Nenhuma transmissão ativa`;
- `Sincronizado`;
- `Reconectando…`.

Evitar linguagem técnica como:

- `estabelecendo conexão websocket`;
- `buffering interno`;
- `sincronização delta`.

Mensagens técnicas devem ser convertidas em linguagem compreensível.

---

## 28. Princípios gerais da UI

### 28.1 O conteúdo vem primeiro

Durante uma transmissão, a interface deve desaparecer visualmente.

### 28.2 Gradiente é assinatura, não fundo padrão

Usar cor com propósito.

### 28.3 Uma ação principal por contexto

Exemplo:

- Home → `Criar sala`;
- Sala vazia → `Iniciar transmissão`.

### 28.4 Menos painéis, mais espaço

Evitar transformar o Semyra em dashboard.

### 28.5 Status deve ser instantaneamente compreendido

Usuário precisa saber rapidamente:

- se existe transmissão;
- se está sincronizado;
- quantas pessoas estão presentes;
- quem está transmitindo.

---

## 29. Design tokens recomendados

```css
:root {
    --color-bg: #050811;
    --color-bg-soft: #0A1020;
    --color-surface: #101827;
    --color-surface-hover: #141F31;

    --color-border: #1C2A3D;
    --color-border-strong: #273851;

    --color-text: #F7F9FC;
    --color-text-soft: #D5DCE8;
    --color-text-muted: #95A3B8;
    --color-text-disabled: #58657A;

    --color-cyan: #16C7FF;
    --color-blue: #2864FF;
    --color-purple: #7B3CFF;
    --color-magenta: #F21BCB;

    --gradient-brand: linear-gradient(
        120deg,
        #16C7FF 0%,
        #2864FF 35%,
        #7B3CFF 65%,
        #F21BCB 100%
    );

    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 18px;
    --radius-xl: 26px;
    --radius-pill: 999px;

    --space-1: 4px;
    --space-2: 8px;
    --space-3: 12px;
    --space-4: 16px;
    --space-5: 20px;
    --space-6: 24px;
    --space-8: 32px;
    --space-10: 40px;
    --space-12: 48px;
    --space-16: 64px;

    --motion-fast: 120ms;
    --motion-base: 180ms;
    --motion-slow: 280ms;
}
```

---

## 30. Componentes oficiais da identidade

A identidade visual deve possuir, no mínimo, os seguintes componentes padronizados:

- logo;
- logo compacta;
- botão primário;
- botão secundário;
- icon button;
- chip de status;
- avatar;
- grupo de avatares;
- input;
- modal;
- card;
- header;
- player;
- barra de progresso;
- volume slider;
- empty state;
- loading state;
- notification/toast.

---

## 31. Direção visual aprovada

As decisões escolhidas até o momento são:

### Home

**Moderna e minimalista.**

Características:

- fundo escuro;
- gradiente suave;
- hero limpo;
- CTA de destaque;
- poucas informações;
- visual premium.

### Sala com transmissão

**Controles minimalistas sobre o vídeo.**

Características:

- conteúdo ocupa quase toda a tela;
- controles sobrepostos;
- topo discreto;
- rodapé funcional;
- interface reduzida ao essencial.

### Sala vazia

**Estado centralizado e limpo.**

Características:

- logo;
- mensagem curta;
- botão `Iniciar transmissão`;
- fundo escuro e sofisticado.

### Header

**Minimalista, fino e responsivo.**

Características:

- logo;
- navegação simples;
- status;
- CTA;
- menu compacto no mobile.

---

## 32. O que não faz parte da identidade

Evitar:

- interface clara como padrão;
- excesso de cards;
- excesso de bordas;
- sombras fortes;
- neon em todos os elementos;
- gradiente em textos longos;
- fontes decorativas;
- glassmorphism exagerado;
- dashboards densos;
- animações chamativas;
- elementos visuais sem função;
- estética gamer pesada;
- estética cyberpunk extrema.

O Semyra deve permanecer sofisticado, simples e fácil de usar.

---

## 33. Resumo da identidade

A identidade visual do Semyra pode ser resumida por:

> **Uma sala de cinema digital, escura e elegante, onde a cor aparece apenas para representar conexão, transmissão e pessoas assistindo juntas.**

Palavras-chave:

- Cinema
- Conexão
- Sincronia
- Pessoas
- Streaming
- Imersão
- Simplicidade
- Tecnologia

Regra central:

> **Quando o conteúdo começa, o Semyra sai do caminho.**


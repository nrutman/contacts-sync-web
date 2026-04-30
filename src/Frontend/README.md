# Front-End Architecture

## Tech Stack

- **UI Components**: [Symfony UX Toolkit](https://ux.symfony.com/toolkit) — Shadcn UI kit (Twig components)
- **CSS**: [Tailwind CSS v4](https://tailwindcss.com/) with oklch color space
- **Icons**: [Lucide](https://lucide.dev/) via `symfony/ux-icons`
- **JavaScript**: [Stimulus.js](https://stimulus.hotwired.dev/) controllers
- **Page Navigation**: [Turbo](https://turbo.hotwired.dev/)
- **Asset Pipeline**: [AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html) (no Node.js/Webpack)

## Component Library

All UI components are installed from the Symfony UX Toolkit's Shadcn kit and live in `templates/components/`. They are Twig components invoked with `<twig:ComponentName>` syntax.

### Installed Components

| Component | Usage |
|---|---|
| `<twig:Button>` | Buttons, link buttons (`as="a"`), variants: default, secondary, destructive, outline, ghost |
| `<twig:Card>` | Card containers with Card:Header, Card:Title, Card:Description, Card:Content, Card:Footer |
| `<twig:Table>` | Data tables with Table:Header, Table:Body, Table:Row, Table:Head, Table:Cell |
| `<twig:Badge>` | Status indicators, variants: default, secondary, destructive, outline |
| `<twig:Alert>` | Flash messages and notices, variants: default, destructive |
| `<twig:AlertDialog>` | Confirmation dialogs (replaces browser `confirm()`) |
| `<twig:Dialog>` | Modal dialogs |
| `<twig:Input>` | Text inputs |
| `<twig:Select>` | Select dropdowns (native `<select>` styled) |
| `<twig:Textarea>` | Textareas |
| `<twig:Checkbox>` | Checkboxes |
| `<twig:Switch>` | Toggle switches |
| `<twig:Label>` | Form labels |
| `<twig:Field>` | Form field wrapper with Field:Label, Field:Content, Field:Description, Field:Error |
| `<twig:Breadcrumb>` | Breadcrumb navigation |
| `<twig:Separator>` | Horizontal separator line |
| `<twig:Spinner>` | Loading spinner animation |
| `<twig:Avatar>` | User avatar with Avatar:Text for initials |
| `<twig:Pagination>` | Pagination controls |

### Installing/Updating Components

```bash
# Install a new component
php bin/console ux:install <component> --kit shadcn

# Example
php bin/console ux:install tooltip --kit shadcn
```

Components are installed to `templates/components/` and can be customized directly.

## Icons

Icons use Lucide via `symfony/ux-icons`:

```twig
<twig:ux:icon name="lucide:icon-name" class="size-4" />
```

Common icons used: `house`, `list`, `users`, `clock`, `settings`, `users-round`, `bell`, `log-out`, `play`, `plus`, `pencil`, `trash-2`, `x`, `circle-check`, `circle-x`, `triangle-alert`, `info`, `menu`, `history`, `lock`, `mail`, `key-round`, `refresh-cw`, `circle-pause`, `circle-play`, `log-in`.

Browse available icons at [lucide.dev/icons](https://lucide.dev/icons).

## Theming

Colors are defined as CSS custom properties in oklch color space in `assets/styles/app.css`.

### Color Tokens

The theme uses semantic color tokens that map to CSS variables:

- `--background` / `--foreground` — Page background and primary text
- `--card` / `--card-foreground` — Card surfaces
- `--primary` / `--primary-foreground` — Primary actions (buttons, links)
- `--secondary` / `--secondary-foreground` — Secondary elements
- `--muted` / `--muted-foreground` — Muted backgrounds and helper text
- `--accent` / `--accent-foreground` — Hover states, active nav items
- `--destructive` / `--destructive-foreground` — Danger/delete actions
- `--border` — Border color
- `--ring` — Focus ring color
- `--sidebar` / `--sidebar-foreground` — Sidebar background and text

Use these in Tailwind classes: `text-foreground`, `bg-primary`, `text-muted-foreground`, `border-border`, etc.

### Dark Mode

Dark mode color overrides are defined in `.dark` class. Activate with `@custom-variant dark (&:is(.dark *))`.

## Symfony Form Theme

Forms are automatically styled via `templates/form/shadcn_theme.html.twig`, registered in `config/packages/twig.yaml`.

The theme maps Symfony form types to Shadcn components:
- Text/email/password/URL inputs → `<twig:Input>`
- Textareas → `<twig:Textarea>`
- Selects → `<twig:Select>`
- Checkboxes → `<twig:Checkbox>` with horizontal Field layout
- Labels → `<twig:Field:Label>`
- Errors → `<twig:Field:Error>`
- Help text → `<twig:Field:Description>`
- Each row → `<twig:Field>` wrapper

Most forms just need `{{ form_row(form.fieldName) }}` — no manual class attributes. The form theme renders extra `attr` entries (e.g. `data-*`, `placeholder`, `class` additions) on all widget types — see the comment at the top of `shadcn_theme.html.twig` for the required pattern when adding new blocks.

## Stimulus Controllers

| Controller | File | Purpose |
|---|---|---|
| `timezone` | `timezone_controller.js` | Converts UTC timestamps to browser local timezone |
| `flash` | `flash_controller.js` | Dismisses flash message alerts |
| `mobile-menu` | `mobile_menu_controller.js` | Toggles mobile sidebar overlay |
| `schedule-picker` | `schedule_picker_controller.js` | Interactive cron schedule builder |
| `sync-all` | `sync_all_controller.js` | Sequential sync of all lists with progress dialog |
| `sync-button` | `sync_button_controller.js` | Loading state for individual sync buttons |
| `sync-status` | `sync_status_controller.js` | Polls API to update running sync rows in-place |
| `csrf-protection` | `csrf_protection_controller.js` | CSRF token handling for API calls |
| `dialog` | `dialog_controller.js` | Shadcn Dialog component controller (auto-installed) |
| `alert-dialog` | `alert_dialog_controller.js` | Shadcn AlertDialog component controller (auto-installed) |

### Timezone Controller

Converts server-rendered UTC timestamps to the user's local timezone:

```twig
<span data-controller="timezone"
      data-timezone-datetime-value="{{ entity.createdAt|date('c') }}"
      data-timezone-format-value="short"
      title="{{ entity.createdAt|date('Y-m-d H:i:s T') }}">
    {{ entity.createdAt|date('M j, g:ia') }} {# no-JS fallback #}
</span>
```

Format values: `short` (default, "Jan 5, 3:42 PM"), `date` ("Jan 5, 2025"), `long` (includes seconds).

## Asset Pipeline

The project uses Symfony AssetMapper — no Node.js or Webpack required.

```bash
# Build Tailwind CSS
php bin/console tailwind:build

# Watch for changes during development
php bin/console tailwind:build --watch

# Install JS dependencies from importmap
php bin/console importmap:install
```

Tailwind scans these paths for class usage (configured in `assets/styles/app.css`):
- `templates/**/*.html.twig` (all templates including components)
- `assets/**/*.js` (Stimulus controllers)

## Adding a New Page

1. Create a controller in `src/Controller/`
2. Create a template extending `base.html.twig` (authenticated) or `base_public.html.twig` (public)
3. Use Shadcn components for UI elements
4. Use `{{ form_row() }}` for form fields (auto-styled by the form theme)
5. Use `<twig:Breadcrumb>` for navigation hierarchy
6. Add `data-controller="timezone"` on any date/time elements

### Template Pattern

```twig
{% extends 'base.html.twig' %}

{% block title %}Page Title — Contacts Sync{% endblock %}

{% block body %}
<div class="mb-8">
    <twig:Breadcrumb>
        <twig:Breadcrumb:List>
            <twig:Breadcrumb:Item>
                <twig:Breadcrumb:Link href="{{ path('app_parent') }}">Parent</twig:Breadcrumb:Link>
            </twig:Breadcrumb:Item>
            <twig:Breadcrumb:Separator />
            <twig:Breadcrumb:Item>
                <twig:Breadcrumb:Page>Current Page</twig:Breadcrumb:Page>
            </twig:Breadcrumb:Item>
        </twig:Breadcrumb:List>
    </twig:Breadcrumb>
    <h1 class="mt-4 text-2xl font-bold text-foreground">Page Title</h1>
    <p class="mt-1 text-sm text-muted-foreground">Description text.</p>
</div>

<div class="max-w-2xl">
    <twig:Card class="p-6">
        {# Content #}
    </twig:Card>
</div>
{% endblock %}
```

<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/SettingsTab.svelte
   * 2. Description: THE SETTINGS BENTO (strip 8c of the p8 STEP 6 dashboard
   *                 split) — the attribution card, the site/brand form (the
   *                 logo IS the field, saved at once), the theme-style card
   *                 (the active template's declared handles + the font), and
   *                 the Integrations rack (GitHub / Mail squares opening the
   *                 parent's modals). Owns both save flows and their
   *                 busy/note slices; `siteSettings` is $bindable — the
   *                 templates tab's theme switch and the crown move mutate
   *                 the same truth. The handles' values seed themselves from
   *                 every fresh load.
   * 3. Expects: `core`, `github`, `mail`, `githubError`, `githubNote` — read
   *             only; `siteSettings` — $bindable; `ongithub` / `onmail` —
   *             open the parent's modals.
   * 4. Provides: The tab. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { saveSiteSettings, uploadSiteAsset, type MailStatus, type ThemeHandle } from '$lib/api';

  type SiteSettings = { name: string; claim: string; logo: string; theme: string; home: string; style: string; font: string; handles: readonly ThemeHandle[] };
  type CoreInfo = {
    current: string;
    latest: string | null;
    error: string;
    localVersion: string | null;
    updatable: boolean;
    dashboard: string | null;
    releaseName: string | null;
    published: string | null;
    contributors: readonly { login: string; url: string }[];
  };

  let {
    siteSettings = $bindable<SiteSettings | null>(null),
    core = null,
    github = null,
    mail = null,
    githubError = '',
    ongithub,
    onmail,
  }: {
    siteSettings?: SiteSettings | null;
    core?: CoreInfo | null;
    github?: { connected: boolean; login: string | null } | null;
    mail?: MailStatus | null;
    githubError?: string;
    ongithub?: () => void;
    onmail?: () => void;
  } = $props();

  import { toast } from '$lib/toast.svelte';

  let siteBusy = $state(false);
  let siteError = $state('');
  let handleValues = $state<Record<string, string>>({});
  let styleBusy = $state(false);
  let styleError = $state('');

  // the handle values seed themselves from every fresh load of the settings
  // (write-only effect: the values it writes are never read here — no loop)
  $effect(() => {
    const loaded = siteSettings;
    if (loaded !== null) {
      handleValues = Object.fromEntries(loaded.handles.map((h) => [h.var, h.value]));
    }
  });

  async function saveSiteNow(event: SubmitEvent) {
    event.preventDefault();
    if (siteSettings === null) return;
    siteBusy = true;
    siteError = '';
    try {
      // the home's crown lives in the page settings modal now (2026-11-09,
      // user order) — the settings form no longer owns it, and a stale
      // snapshot here must never uncrown the page the modal crowned.
      // style went the same road (2026-11-09, third telling): no settings
      // surface speaks for style any more.
      await saveSiteSettings({
        name: siteSettings.name,
        claim: siteSettings.claim,
        logo: siteSettings.logo,
        font: siteSettings.font
      });
      toast('Saved. The public face updates on the next serve.');
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }

  /** What a color CHANGES, not what it's called (2026-11-12, user order):
   *  "Crust — panels & header" speaks as "panels & header"; a label without
   *  the dash keeps its whole words. */
  function shortHandleLabel(label: string): string {
    const dash = label.indexOf('—') !== -1 ? '—' : label.indexOf('–') !== -1 ? '–' : ' — ';
    const at = label.indexOf(dash);
    const short = at !== -1 ? label.slice(at + dash.length).trim() : label.trim();
    return short || label;
  }

  async function saveHandlesNow() {
    if (siteSettings === null) return;
    styleBusy = true;
    styleError = '';
    try {
      const handles: Record<string, string> = {};
      for (const h of siteSettings.handles) {
        const value = handleValues[h.var] ?? h.default;
        handles[h.var] = value === h.default ? '' : value;
      }
      // the card's save speaks for the whole card (2026-11-12, user order):
      // the handles AND the font — the font field lives here now.
      await saveSiteSettings({ handles, font: siteSettings.font });
      toast('Saved. The public face updates on the next serve.');
    } catch (e) {
      styleError = e instanceof Error ? e.message : String(e);
    } finally {
      styleBusy = false;
    }
  }

  /** Back to the template's default: the handle leaves the map, the theme's
   *  own :root speaks again. */
  function resetHandle(name: string, fallback: string) {
    handleValues = { ...handleValues, [name]: fallback };
  }

  /** The logo upload: the picture IS the field — pick, upload, and it lands
   *  on the public face immediately (saved at once; the site form's Save
   *  button no longer carries the logo). */
  async function onLogoPick(event: Event) {
    const input = event.currentTarget as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || siteSettings === null) return;
    siteBusy = true;
    siteError = '';
    try {
      const stored = await uploadSiteAsset(file);
      await saveSiteSettings({ logo: stored.url });
      siteSettings = { ...siteSettings, logo: stored.url };
      toast(`Logo uploaded as ${stored.name} — on the public face at once.`);
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }

  /** The logo lets go — cleared and saved in one stroke. */
  async function clearLogoNow() {
    if (siteSettings === null || siteBusy) return;
    siteBusy = true;
    siteError = '';
    try {
      await saveSiteSettings({ logo: '' });
      siteSettings = { ...siteSettings, logo: '' };
      toast('The logo let go.');
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }

  /** The font upload: same pantry as the logo — the stored URL lands in the
   *  font field; SAVE puts it on the public face. */
  async function onFontPick(event: Event) {
    const input = event.currentTarget as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || siteSettings === null) return;
    siteBusy = true;
    siteError = '';
    try {
      const stored = await uploadSiteAsset(file);
      siteSettings = { ...siteSettings, font: stored.url };
      toast(`Font uploaded as ${stored.name} — save to put it on the public face.`);
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }
</script>

<!-- THE BENTO (2026-11-11, user order): 5 columns x 2 rows — site
     settings 1x1, theme style 2x1; the empty cells wait for later
     freight. Every tile scrolls inside itself: the page never moves. -->
{#if siteSettings}
  <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 min-h-0 overflow-y-auto">
    <!-- THE ATTRIBUTION CARD (2026-11-12, user order): first in the
         bento, 1x1. The release's own name + day, the people behind
         it, the way to support it, the door back to GitHub. -->
    <section class="mg flex flex-col gap-2 lg:col-span-1">
      <h4 class="mg-h">Schmarrn {core?.releaseName ?? core?.current ?? ''}</h4>
      {#if core?.published}<p class="m-0 text-xs text-ink-soft">published {core.published}</p>{/if}
      <hr class="border-line" />
      <p class="m-0 text-sm">
        Attribution:
        {#each core?.contributors ?? [] as person (person.login)}
          <a href={person.url} target="_blank" rel="noopener" class="underline">{person.login}</a>{' '}
        {/each}
        <span>Cornelia Diomis, Chinami Sato</span>
      </p>
      <hr class="border-line" />
      <p class="m-0 text-sm">Support the project via <span class="underline">Voxerl</span></p>
      <hr class="border-line" />
      <a
        class="btn btn-secondary self-start"
        href="https://github.com/swisd-project/schmarrn"
        target="_blank"
        rel="noopener"
      >GitHub ↗</a>
      <p class="m-0 text-xs text-ink-soft">
        © {new Date().getFullYear()}
        <a href="https://homahuki.eu" target="_blank" rel="noopener" class="underline">Homahuki</a>
        ·
        <a href="https://hideosnes.online" target="_blank" rel="noopener" class="underline">Hidéo Snes</a>
      </p>
    </section>
    <form class="mg flex flex-col gap-3 lg:col-span-1" onsubmit={saveSiteNow}>
      <h4 class="mg-h">Site settings</h4>
      <label class="field"><span>Site name</span><input class="input" bind:value={siteSettings.name} required /></label>
      <label class="field"><span>Claim</span><input class="input" bind:value={siteSettings.claim} /></label>
      <div class="field">
        <span>Logo</span>
        <!-- the logo face (2026-11-12, user order): no filepath, no
             text field — the picture IS the field. Clicking it offers
             the upload; the × lets it go. Both land saved at once. -->
        <div class="flex items-center gap-2">
          <label
            class="relative block w-16 h-16 border border-line rounded overflow-hidden bg-batter/40 hover:bg-batter/70 cursor-pointer grid place-items-center shrink-0"
            title="Upload a logo"
          >
            <input class="hidden" type="file" accept="image/*" onchange={onLogoPick} disabled={siteBusy} />
            {#if siteSettings.logo}
              <img src={siteSettings.logo} alt="Logo" class="max-w-full max-h-full object-contain p-1" />
            {:else}
              <span class="text-xs text-ink-soft">{siteBusy ? '…' : 'upload'}</span>
            {/if}
          </label>
          {#if siteSettings.logo}
            <button type="button" class="btn-ghost text-xs shrink-0" title="Remove the logo" onclick={clearLogoNow} disabled={siteBusy}>×</button>
          {/if}
        </div>
      </div>
      <button type="submit" class="btn btn-primary self-start" disabled={siteBusy}>{siteBusy ? 'Saving…' : 'Save site'}</button>
      {#if siteError}<p class="alert alert-err m-0" role="alert">{siteError}</p>{/if}
    </form>

    <section class="mg flex flex-col gap-3 lg:col-span-2">
      <h4 class="mg-h">Theme style</h4>
    {#if siteSettings.handles.length === 0}
      <p class="italic text-ink-soft m-0">The active template declares no handles — its stylesheet is the only way in.</p>
    {:else}
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
        <!-- LEFT: the color stack (2026-11-12, user order) — squares as
             tall as the text, stacked vertically, each with what it
             CHANGES beside it. The theme's color NAMES are gone: a
             label like "Crust — panels & header" speaks as "panels &
             header". The hook returns the template's default. -->
        <div class="flex flex-col gap-1.5">
          {#each siteSettings.handles.filter((h) => h.type === 'color') as handle (handle.var)}
            <div class="flex items-center gap-2">
              <input
                type="color"
                class="w-6 h-6 p-0 border border-line rounded cursor-pointer bg-transparent shrink-0"
                bind:value={handleValues[handle.var]}
                title={handle.label}
                aria-label={handle.label}
              />
              <button
                type="button"
                class="btn-ghost text-xs shrink-0"
                title="Back to the template's default ({handle.label})"
                onclick={() => resetHandle(handle.var, handle.default)}
              >↺</button>
              <span class="text-sm text-ink-soft truncate">{shortHandleLabel(handle.label)}</span>
            </div>
          {/each}
        </div>
        <!-- RIGHT: the type side — the font moved here (2026-11-12,
             user order) from the site form; text handles ride along. -->
        <div class="flex flex-col gap-3">
          {#each siteSettings.handles.filter((h) => h.type !== 'color') as handle (handle.var)}
            <div class="field">
              <span>{handle.label}</span>
              <div class="flex items-center gap-2">
                <input class="input" bind:value={handleValues[handle.var]} aria-label={handle.label} />
                <button type="button" class="btn-ghost" title="Back to the template's default" onclick={() => resetHandle(handle.var, handle.default)}>↺</button>
              </div>
            </div>
          {/each}
          <label class="field">
            <span>Font <span class="hint">(Google name, https:// URL, or upload)</span></span>
            <input class="input" bind:value={siteSettings.font} placeholder="e.g. Comic Neue — empty = theme default" />
            <label class="btn btn-secondary self-start cursor-pointer">
              <input class="hidden" type="file" accept=".woff2,.woff,.ttf,.otf" onchange={onFontPick} disabled={siteBusy} />
              {siteBusy ? 'Uploading…' : 'Upload font…'}
            </label>
            <span class="hint">A plain name uses Google Fonts; a https:// link is used as-is; an uploaded file is served from the site itself.</span>
          </label>
        </div>
      </div>
      <button type="button" class="btn btn-primary self-start" onclick={saveHandlesNow} disabled={styleBusy}>{styleBusy ? 'Saving…' : 'Save style'}</button>
      {#if styleError}<p class="alert alert-err m-0" role="alert">{styleError}</p>{/if}
    {/if}
    </section>

    <!-- THE INTEGRATIONS CARD (2026-11-12, user order): last in the
         bento, 1x1. A rack of squares, two per row — GitHub is the
         first; a live logo wears the primary colour. -->
    <section class="mg flex flex-col gap-3 lg:col-span-1">
      <h4 class="mg-h">Integrations</h4>
      <div class="grid grid-cols-2 gap-2">
        <button
          class="flex flex-col items-center justify-center gap-2 aspect-square border border-line bg-batter/40 hover:bg-batter/70 transition-colors"
          title={github?.connected ? `GitHub — connected as ${github.login}` : 'GitHub — connect an account'}
          onclick={() => ongithub?.()}
        >
          <svg
            viewBox="0 0 24 24"
            class="w-12 h-12 {github?.connected ? 'text-jam' : 'text-ink-soft'}"
            fill="currentColor"
            aria-hidden="true"
          >
            <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
          </svg>
          <span class="text-xs font-bold">GitHub</span>
        </button>
        <!-- the SMTP oven, second in the rack (2026-11-12, user order):
             invites and password resets ride it; plumbed in, the mark
             wears the primary colour -->
        <button
          class="flex flex-col items-center justify-center gap-2 aspect-square border border-line bg-batter/40 hover:bg-batter/70 transition-colors"
          title={mail?.configured ? `Mail — plumbed in via ${mail.host}` : 'Mail — connect an SMTP server'}
          onclick={() => onmail?.()}
        >
          <svg
            viewBox="0 0 24 24"
            class="w-12 h-12 {mail?.configured ? 'text-jam' : 'text-ink-soft'}"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            aria-hidden="true"
          >
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M3 7l9 6 9-6" />
          </svg>
          <span class="text-xs font-bold">Mail</span>
        </button>
      </div>
      {#if github?.connected}
        <p class="m-0 text-xs text-ink-soft">connected as <b>{github.login}</b></p>
      {/if}
      {#if mail?.configured}
        <p class="m-0 text-xs text-ink-soft">plumbed in via <b>{mail.host}</b></p>
      {/if}
      {#if githubError}<p class="alert alert-err m-0" role="alert">{githubError}</p>{/if}
    </section>
  </div>
{:else}
  <p class="italic text-ink-soft">Reaching for the settings…</p>
{/if}

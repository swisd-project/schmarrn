<script lang="ts">
  // Login wall — one password, one session, no drama.
  import { session, login, logout, pageCount, type PageIndex } from '$lib/api';

  let authenticated = $state<boolean | null>(null);
  let password = $state('');
  let error = $state('');
  let busy = $state(false);
  let index = $state<PageIndex | null>(null);

  // establish session state once
  $effect(() => {
    session().then((s) => (authenticated = s.authenticated)).catch(() => (authenticated = false));
  });

  async function signIn(event: SubmitEvent) {
    event.preventDefault();
    busy = true;
    error = '';
    try {
      await login(password);
      authenticated = true;
      password = '';
    } catch (e) {
      error = e instanceof Error ? e.message : 'Login failed';
    } finally {
      busy = false;
    }
  }

  async function signOut() {
    await logout();
    authenticated = false;
  }

  // load the page tree the moment we're in
  $effect(() => {
    if (authenticated === true && index === null) {
      pageCount().then((i) => (index = i)).catch((e) => (error = e.message));
    }
  });
</script>

<main class="shell">
  {#if authenticated === null}
    <p aria-live="polite">Reaching for the pantry…</p>
  {:else if authenticated === false}
    <form onsubmit={signIn} class="login">
      <h1>Schmarrn</h1>
      <input type="password" bind:value={password} placeholder="Kitchen password" required />
      <button type="submit" disabled={busy}>{busy ? 'Plating…' : 'Schnitten!'}</button>
      {#if error}<p class="error">{error}</p>{/if}
    </form>
  {:else}
    <header>
      <h1>Schmarrn</h1>
      <button onclick={signOut}>Log out</button>
    </header>
    {#if index}
      <ul class="tree">
        {#each Object.entries(index) as [type, pages] (type)}
          <li>
            <strong>{type}</strong>
            <ul>
              {#each pages as page (page.slug)}
                <li>{page.slug}</li>
              {/each}
            </ul>
          </li>
        {/each}
      </ul>
    {/if}
  {/if}
</main>

<style>
  .shell { max-width: 60rem; margin: 0 auto; padding: 2rem; font-family: system-ui, sans-serif; }
  .login { display: flex; flex-direction: column; gap: 0.75rem; max-width: 16rem; }
  .error { color: #b3004c; }
  .tree { line-height: 1.8; }
  header { display: flex; justify-content: space-between; align-items: baseline; }
</style>

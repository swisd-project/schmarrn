<script lang="ts">
  import '../lib/app.css';

  let { children } = $props();
</script>

<svelte:head>
  <!-- the soft law's face (2026-11-15, user order): Cormorant Upright —
       300 body, 400 headlines; the weights the font ships with -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="" />
  <link
    rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Cormorant+Upright:wght@300;400;600;700&display=swap"
  />
</svelte:head>

{@render children?.()}

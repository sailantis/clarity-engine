# Benchmark

How Clarity compares with the other popular PHP template engines, across four rendering shapes: a page of scalars, a heavy page of escapes and loops, a page of objects, and the same objects held as nested arrays. The main README shows one of these at a glance; this page is the comparison in full.

> **Generated — do not edit by hand.** Every figure below comes from a benchmark run's own JSON and is re-rendered from it, so the numbers cannot drift from the data they came from. To update this page, re-run the harness and re-publish; see the `azera-competition` repository.

<!-- view-engine:begin -->
Every engine renders the same template shape, in the same session — a difference between two engines is the engines, a difference between two sections is the work the page does. Compare engines WITHIN a shape; a ranking read across sections ranks the pages, not the engines.

## Mixed

What the page renders, and so what these figures are the cost of: the heavy page: 20 flat variables each read twice (as text and as a data-value attribute) plus 20 rows of six fields each, over 40 flat variable accesses per render and 1440 in total at 200 items, with every value HTML-special so the escape path does real work in both the body and an attribute context.

### Time per render — Mixed

![Time per render — Mixed](images/benchmarks/mixed-render-time.svg)

Dot: median render · caps: fastest observation and p95 · multiplier: median ÷ the fastest median.

### First render — Mixed

![First render — Mixed](images/benchmarks/mixed-warm-cost.svg)

The one-off cost of the first request after a deploy: engine boot, template compile, cache write and one render. Measured once per engine, each in its own fresh process; OPcache is on but its CLI segment is per-process, so the engine source is compiled there too.

### Memory per run — Mixed

![Memory per run — Mixed](images/benchmarks/mixed-memory.svg)

Reads the PHP heap. Left cap: what the engine costs to have loaded, nothing rendered. Dot: what a whole run still holds, after a gc pass — memory_get_usage(false) after a whole run of iterations_per_run renders in a fresh process, gc_collect_cycles() called first. Right cap: the run's high-water mark. Memory probe: a fresh process with opcache.enable_cli=1 but a per-process CLI segment, so engine source is compiled in that process. Every reading is deterministic, so this is not a spread of repeats.

### Results — Mixed

Generated from the same rows the charts are drawn from. Rows are ordered by median, fastest first.

| Engine | First render (ms) | Mean (ms) | Median (ms) | Min (ms) | p95 (ms) | Retained (MB) | Peak (MB) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Clarity | 20.699 | 0.328 | 0.317 | 0.291 | 0.386 | 1.17 | 1.65 |
| Stempler | 31.004 | 0.341 | 0.328 | 0.309 | 0.404 | 1.45 | 2.00 |
| Native | 0.661 | 0.352 | 0.341 | 0.319 | 0.409 | 0.91 | 1.61 |
| Plates | 2.308 | 0.415 | 0.397 | 0.372 | 0.497 | 0.98 | 1.61 |
| Latte | 51.510 | 0.524 | 0.502 | 0.468 | 0.624 | 1.58 | 9.71 |
| Blade | 31.817 | 0.639 | 0.620 | 0.575 | 0.754 | 1.69 | 2.12 |
| Twig | 37.266 | 0.863 | 0.834 | 0.786 | 1.014 | 1.65 | 2.15 |

## Objects

What the page renders, and so what these figures are the cost of: objects instead of scalars: two properties, a nested object, a nullable property behind a default, and an array inside an object.

### Time per render — Objects

![Time per render — Objects](images/benchmarks/entities-render-time.svg)

Dot: median render · caps: fastest observation and p95 · multiplier: median ÷ the fastest median.

### First render — Objects

![First render — Objects](images/benchmarks/entities-warm-cost.svg)

The one-off cost of the first request after a deploy: engine boot, template compile, cache write and one render. Measured once per engine, each in its own fresh process; OPcache is on but its CLI segment is per-process, so the engine source is compiled there too.

### Memory per run — Objects

![Memory per run — Objects](images/benchmarks/entities-memory.svg)

Reads the PHP heap. Left cap: what the engine costs to have loaded, nothing rendered. Dot: what a whole run still holds, after a gc pass — memory_get_usage(false) after a whole run of iterations_per_run renders in a fresh process, gc_collect_cycles() called first. Right cap: the run's high-water mark. Memory probe: a fresh process with opcache.enable_cli=1 but a per-process CLI segment, so engine source is compiled in that process. Every reading is deterministic, so this is not a spread of repeats.

### Results — Objects

Generated from the same rows the charts are drawn from. Rows are ordered by median, fastest first.

| Engine | First render (ms) | Mean (ms) | Median (ms) | Min (ms) | p95 (ms) | Retained (MB) | Peak (MB) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Clarity | 19.672 | 0.115 | 0.107 | 0.094 | 0.146 | 1.05 | 1.61 |
| Native | 0.331 | 0.119 | 0.112 | 0.096 | 0.148 | 0.81 | 1.61 |
| Stempler | 23.472 | 0.124 | 0.117 | 0.104 | 0.156 | 1.32 | 1.61 |
| Plates | 1.958 | 0.150 | 0.139 | 0.126 | 0.192 | 0.88 | 1.61 |
| Latte | 44.224 | 0.305 | 0.288 | 0.267 | 0.374 | 1.46 | 6.89 |
| Blade | 30.406 | 0.332 | 0.319 | 0.297 | 0.400 | 1.59 | 2.01 |
| Twig | 38.442 | 0.626 | 0.602 | 0.563 | 0.740 | 1.54 | 1.76 |

## What the columns are

- **First render** — the first request after a deploy, in a fresh process per engine: boot, compile, cache write, one render. The template cache is cold.
- **Mean / Median / Min / p95** — over every individual render across all runs.
- **Retained** — heap still held after a whole run, after a gc pass, in a fresh process.
- **Peak** — the same run's high-water mark, where the first compile's transient allocation lives. Not a second measurement of Retained.

A few percent is a tie, not a win: it is within a single engine's own run-to-run spread.

**Environment** — PHP 8.3.33 · Linux 6.8.0-139-generic · SAPI cli · OPcache (`opcache.enable_cli`): yes

**Budget** — 10,000 renders × 30 runs, 200 items per render

**Method** — Steady-state timings: the render loop for each (engine, page) cell runs in its own fresh process against a warm cache: one untimed warm-up render, then runs x iterations-per-run timed renders. No order: each (engine, page) cell is measured in its own process, so measurement order cannot affect a cell. The first render was measured as one render in a fresh process with a cold template cache: engine class loading, template compile, cache write and one render.

**Engines** — Clarity dev-main (v0.3.0) · NativeEngine (Azera) dev-main (78444bf) · Plates 3.6.0 · Blade 12.69.2 · Twig 3.27.0 · Stempler 3.17.2 · Latte 3.1.6

_Measured 2026-10-10T18:57:36+00:00_

The full published report, including the headline page: <https://sailantis.github.io/azera-competition/benchmarks/view-engine.html>
<!-- view-engine:end -->

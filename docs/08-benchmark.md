# Benchmark

How Clarity compares with the other mainstream PHP template engines, across four rendering shapes: a page of scalars, a heavy page of escapes and loops, a page of objects, and the same objects held as nested arrays. The main README shows one of these at a glance; this page is the comparison in full.

> **Generated — do not edit by hand.** Every figure below comes from a benchmark run's own JSON and is re-rendered from it, so the numbers cannot drift from the data they came from. To update this page, re-run the harness and re-publish; see the `azera-competition` repository.

<!-- view-engine:begin -->
Every engine was measured rendering the SAME template shape, on the same machine and PHP build and in the same session, so a difference between two engines is the engines and a difference between two sections is the work the page does.

Compare engines WITHIN a shape. The sections below are different templates doing different work with different data, so a time in one and a time in another are different measurements — a ranking read across sections would be a ranking of the pages, not of the engines.

## Mixed

What this page renders, and so what these numbers are the cost of: the heavy page: 20 flat variables each read twice (as text and as a data-value attribute) plus 20 rows of six fields each, over 40 flat variable accesses per render and 1440 in total at 200 items, with every value HTML-special so the escape path does real work in both the body and an attribute context.

### Time per render — Mixed

![Time per render — Mixed](images/benchmarks/mixed-render-time.svg)

The dot is the median render and the caps bound the fastest observation and p95, so an engine that is usually fast but occasionally slow looks different from one that is uniformly slower. The multiplier beside each row is measured against the fastest median in the chart.

### First render — Mixed

![First render — Mixed](images/benchmarks/mixed-warm-cost.svg)

The one-off cost the first request after a deploy pays: the engine's classes load, the template compiles, the cache is written and the page renders once. Measured in a fresh process per engine, so no engine is measured against a cache another engine already paid for.

### Memory per run — Mixed

![Memory per run — Mixed](images/benchmarks/mixed-memory.svg)

Three marks, three measurements: the left cap is the engine loaded with nothing rendered, the dot is the heap retained after the run (gc collected) and the right cap is the run's peak. Not a spread of repeats — every reading is deterministic.

### Results — Mixed

Generated from the same rows the charts above are drawn from. Rows are ordered by median, fastest first.

| Engine | First render (ms) | Mean (ms) | Median (ms) | Min (ms) | p95 (ms) | Retained (MB) | Peak (MB) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Clarity | 17.059 | 0.324 | 0.315 | 0.295 | 0.374 | 1.17 | 1.61 |
| Stempler | 29.220 | 0.338 | 0.327 | 0.308 | 0.390 | 1.45 | 2.00 |
| Native | 0.617 | 0.351 | 0.341 | 0.316 | 0.401 | 0.91 | 1.61 |
| Plates | 2.232 | 0.405 | 0.394 | 0.368 | 0.466 | 0.98 | 1.61 |
| Latte | 50.265 | 0.515 | 0.496 | 0.465 | 0.600 | 1.58 | 9.71 |
| Blade | 33.001 | 0.635 | 0.618 | 0.576 | 0.736 | 1.69 | 2.12 |
| Twig | 36.328 | 0.851 | 0.827 | 0.776 | 0.981 | 1.65 | 2.15 |

## Objects

What this page renders, and so what these numbers are the cost of: objects instead of scalars: two properties, a nested object, a nullable property behind a default, and an array inside an object.

### Time per render — Objects

![Time per render — Objects](images/benchmarks/entities-render-time.svg)

The dot is the median render and the caps bound the fastest observation and p95, so an engine that is usually fast but occasionally slow looks different from one that is uniformly slower. The multiplier beside each row is measured against the fastest median in the chart.

### First render — Objects

![First render — Objects](images/benchmarks/entities-warm-cost.svg)

The one-off cost the first request after a deploy pays: the engine's classes load, the template compiles, the cache is written and the page renders once. Measured in a fresh process per engine, so no engine is measured against a cache another engine already paid for.

### Memory per run — Objects

![Memory per run — Objects](images/benchmarks/entities-memory.svg)

Three marks, three measurements: the left cap is the engine loaded with nothing rendered, the dot is the heap retained after the run (gc collected) and the right cap is the run's peak. Not a spread of repeats — every reading is deterministic.

### Results — Objects

Generated from the same rows the charts above are drawn from. Rows are ordered by median, fastest first.

| Engine | First render (ms) | Mean (ms) | Median (ms) | Min (ms) | p95 (ms) | Retained (MB) | Peak (MB) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Clarity | 17.219 | 0.114 | 0.108 | 0.096 | 0.140 | 1.05 | 1.61 |
| Native | 0.348 | 0.118 | 0.112 | 0.100 | 0.142 | 0.81 | 1.61 |
| Stempler | 23.107 | 0.123 | 0.117 | 0.103 | 0.149 | 1.32 | 1.61 |
| Plates | 1.958 | 0.145 | 0.138 | 0.124 | 0.173 | 0.88 | 1.61 |
| Latte | 46.728 | 0.300 | 0.286 | 0.256 | 0.357 | 1.46 | 6.89 |
| Blade | 30.094 | 0.326 | 0.315 | 0.293 | 0.390 | 1.59 | 2.01 |
| Twig | 34.703 | 0.620 | 0.599 | 0.567 | 0.724 | 1.54 | 1.76 |

## What the columns are

- **First render** — the first request after a deploy, measured in a fresh process per engine: engine boot, template compile, cache write and one render. The TEMPLATE cache is cold; OPcache is on but its CLI segment is per-process, so the engine source is compiled in that process. Comparable across engines because no engine inherits another's warm template cache or loaded classes.
- **Mean / Median / Min / p95** — computed over every individual render across all runs.
- **Retained** — PHP heap still held after a whole run of renders, with `gc_collect_cycles()` called before the reading, in a fresh process. This is what a process carries while serving.
- **Peak** — the same run's PHP heap high-water mark, which is where the transient allocation of the first compile lives. It sits above Retained and is not a second measurement of it.

Two engines sitting next to each other at the top of a table are not thereby ranked: a difference of a few percent is still within the spread of a single engine's own runs, and a gap that small is a tie, not a win.

**Environment** — PHP 8.3.33 · Linux 6.8.0-139-generic · SAPI cli · OPcache (`opcache.enable_cli`): yes · Memory probe: `a fresh process with opcache.enable_cli=1 but a per-process CLI segment, so engine source is compiled in that process`

**Budget** — 10,000 renders × 30 runs, 200 items per render

**Method** — Steady-state timings: the render loop for each (engine, page) cell runs in its own fresh process against a warm cache: one untimed warm-up render, then runs x iterations-per-run timed renders. No order: each (engine, page) cell is measured in its own process, so measurement order cannot affect a cell. The first render was measured as one render in a fresh process with a cold template cache: engine class loading, template compile, cache write and one render.

**Engines** — Clarity dev-main (84fb729+dirty) · NativeEngine (Azera) dev-main (5a0c30e+dirty) · Plates 3.6.0 · Blade 12.69.2 · Twig 3.27.0 · Stempler 3.17.2 · Latte 3.1.6

_Measured 2026-10-01T01:00:59+00:00_

Every chart on this page is a plain SVG generated from the run's own JSON, so a number and a diagram cannot disagree. The full published report, including the headline page and each shape's live page: <https://sailantis.github.io/azera-competition/benchmarks/view-engine.html>
<!-- view-engine:end -->

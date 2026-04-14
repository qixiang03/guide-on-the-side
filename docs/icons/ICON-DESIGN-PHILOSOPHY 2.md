# Marginalia — Icon Design Philosophy

## A visual philosophy for the pb-split-guide iconography

---

## The Movement

**Marginalia** is the language of scholars working at the edge of a page. It is the scribe's compass, the botanist's pen, the cartographer's gauge — reduced to their essential geometry and redrawn with modernist restraint. It is the quiet authority of the stacks: the card catalog's ruled precision meeting the ornamental discipline of a Renaissance printer's mark. The system does not shout. It notates. It marks with the calm of someone who has been marking for a very long time, and who understands that the smallest gestures are the ones that last.

## Form and Space

Every glyph is constructed on a 24-unit grid, a dimensional vellum upon which form is drawn with the unwavering hand of a master draughtsman. Lines are thin — 1.5 units thick — and they hold the weight of the composition not through mass but through conviction. Form emerges from the subtraction of everything unnecessary: a book becomes two meeting arcs, a lock becomes a rectangle and a hoop, a page becomes a square with a folded corner. Each icon is built from the smallest possible vocabulary of circles, straight lines, and right angles, meticulously placed so that every negative space breathes and every intersection is the result of painstaking calibration. Nothing is drawn twice. Nothing is rendered that could be implied.

## Line and Weight

The line is monochromatic and inherits its color from its context — `currentColor` — so that each mark becomes a whisper or a statement depending on the page that holds it. Stroke caps and joins are rounded, a gentle humanization of Swiss geometric rigor: the corners do not cut, they pause. This restraint is not a limitation but a discipline, the product of deep expertise in what to leave out. The work must appear as though it were labored over across countless hours by someone who has drawn each shape a hundred times before settling on the version that sings. Every radius, every endpoint, every gap between strokes is the final answer to a long interrogation.

## Rhythm and Repetition

Icons appear in families and sets, and the family resemblance is non-negotiable. A document icon shares its folded corner with another document icon in a different context. A lock closed and a lock open are the same lock, one opened quietly. Chevrons are the same chevron, rotated. This rhythm — the patient recurrence of the same vocabulary across different meanings — is the core visual gesture of the system. It is the craft of a bookbinder's signature, of an engraver who works through an entire alphabet before declaring any single letter complete. The repetition rewards sustained looking: the viewer learns the grammar, and then the icons speak in sentences.

## Composition and Hierarchy

Each mark is centered in its grid with mathematical care, with generous margins so that the glyph breathes inside whatever container holds it. No icon touches its own boundary. No stroke runs alone — every element is balanced by another element across the invisible axis. The eye traverses the icon the way it traverses a diagram in a pre-cinema encyclopedia: slowly, rewarded by detail, finding the labelled parts of an object it has known all its life but had never really looked at. Scale and placement are the product of master-level execution — the kind of precision that is only visible when it is absent, because absent precision is the first thing anyone notices.

## The Unifying Discipline

Marginalia is not a collection. It is a single instrument, tuned once, played for a long time. Every addition to the set is held to the standard of the first glyph that established the vocabulary — a standard of painstaking attention, of craftsmanship so total that the hand disappears and only the idea remains. The system must feel inevitable, as though the glyphs had been waiting in the grid the whole time and someone simply had the discipline to find them. This is the quiet labor of someone at the top of their field: drawing the same circle a thousand times so that one circle, placed precisely in a 24-unit field, can stand for a globe, a stopwatch, a lock, a thought.

---

## Concrete parameters

- Grid: **24 × 24**
- Stroke width: **1.5**
- Stroke color: **`currentColor`** (inherits from CSS)
- Fill: **`none`** on all strokes; fills permitted only for negative dots/accents
- Linecaps / linejoins: **round**
- Geometric vocabulary: circle, line, arc, right angle
- Margin: minimum **2 units** of clearance inside the viewBox
- Naming: `kebab-case`, semantic (e.g. `lock-closed`, not `padlock`)

/**
 * DOM-scan helper for the PageBuilder ModEl field.
 *
 * Mirrors the pattern used by CkEditor's `check.js`:
 *   - `checkPageBuilder()` scans for `.pagebuilder_field` textareas and mounts
 *     a PageBuilder instance next to each one.
 *   - `getPageBuilderValue` / `setPageBuilderValue` are wired via
 *     `data-getvalue-function` / `data-setvalue-function` so ModEl's form save
 *     reads/writes the JSON through the live builder.
 */

const pbInstancesArr = [];

const EMPTY_DOC_JSON = '{"version":1,"root":[]}';

function parseDoc(raw) {
	if (!raw)
		return JSON.parse(EMPTY_DOC_JSON);
	try {
		const parsed = JSON.parse(raw);
		if (parsed && typeof parsed === 'object' && parsed.version === 1 && Array.isArray(parsed.root))
			return parsed;
		console.warn('[page-builder] stored value is not a valid document, resetting');
	} catch (e) {
		console.warn('[page-builder] failed to parse stored JSON, resetting', e);
	}
	return JSON.parse(EMPTY_DOC_JSON);
}

function parseLanguages(textarea) {
	const raw = textarea.getAttribute('data-pb-languages');
	if (!raw)
		return ['it'];
	try {
		const langs = JSON.parse(raw);
		if (Array.isArray(langs) && langs.length)
			return langs;
	} catch (e) {
		console.warn('[page-builder] failed to parse data-pb-languages', e);
	}
	return ['it'];
}

// Sources are declared once globally (PageBuilder module config), so the sample
// data is the same for every field on the page — fetch it at most once.
let sampleDataPromise = null;
let fragmentsPromise = null;

function fetchSampleData() {
	if (sampleDataPromise === null) {
		sampleDataPromise = fetch(PATH + 'page-builder/sample-data', {
			credentials: 'include',
		})
			.then((res) => (res.ok ? res.json() : null))
			.then((data) => (data && data.sources && typeof data.sources === 'object' ? data.sources : {}))
			.catch((e) => {
				console.warn('[page-builder] failed to fetch sample data', e);
				return {};
			});
	}
	return sampleDataPromise;
}

function fetchFragments() {
	if (fragmentsPromise === null) {
		fragmentsPromise = fetch(PATH + 'page-builder/fragments', {
			credentials: 'include',
		})
			.then((res) => (res.ok ? res.json() : null))
			.then((data) => (data && Array.isArray(data.fragments) ? data.fragments : []))
			.catch((e) => {
				console.warn('[page-builder] failed to fetch fragments', e);
				return [];
			});
	}
	return fragmentsPromise;
}

function parseDataSources(textarea) {
	const raw = textarea.getAttribute('data-pb-datasources');
	if (!raw)
		return null;
	try {
		const ds = JSON.parse(raw);
		if (Array.isArray(ds) && ds.length)
			return ds;
	} catch (e) {
		console.warn('[page-builder] failed to parse data-pb-datasources', e);
	}
	return null;
}

function parseComponents(textarea) {
	const raw = textarea.getAttribute('data-pb-components');
	if (!raw)
		return null;
	try {
		const list = JSON.parse(raw);
		if (Array.isArray(list) && list.length)
			return list;
	} catch (e) {
		console.warn('[page-builder] failed to parse data-pb-components', e);
	}
	return null;
}

// Register the host's custom components on the shared global registry (idempotent
// across fields). None carry a JS render. A LEAF gets `serverRender:true` so the
// editor fetches its preview HTML from the render-node route via onRenderComponent.
// A CONTAINER (`acceptsChildren`) is registered as-is and rendered in-canvas by the
// editor's default container render, keeping its children authorable (its real
// wrapper is applied server-side by its PHP template).
//
// A descriptor whose type collides with a builtin is normally skipped (protects the
// core builtins). The one exception: a builtin that opts in with `overridable:true`
// (the native `slider`) IS replaced — that's how the model/slider package's `slider`
// supersedes the native Bootstrap carousel in the editor, mirroring the PHP side.
function registerCustomComponents(list) {
	if (!list || typeof window.PageBuilder.registerComponent !== 'function')
		return;
	const builtins = window.PageBuilder.builtins || {};
	for (const def of list) {
		if (!def || typeof def.type !== 'string')
			continue;
		const builtinDef = builtins[def.type];
		const overridable = builtinDef && builtinDef.overridable === true;
		if (builtinDef && !overridable)
			continue;
		try {
			const definition = def.acceptsChildren === true ? { ...def } : { ...def, serverRender: true };
			if (overridable && typeof window.PageBuilder.overrideComponent === 'function')
				window.PageBuilder.overrideComponent(def.type, definition);
			else
				window.PageBuilder.registerComponent(def.type, definition);
		} catch (e) {
			// Already registered by an earlier field on the page — that's fine.
		}
	}
}

// Render one node to preview HTML via the PHP renderer (the source of truth). The
// editor calls this for every server-rendered component; results are cached editor-
// side by content key, so this fires at most once per distinct config.
async function renderComponentNode(node, opts) {
	const res = await fetch(PATH + 'page-builder/render-node', {
		method: 'POST',
		credentials: 'include',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ node, lang: opts && opts.lang }),
		signal: opts && opts.signal,
	});
	if (!res.ok)
		throw new Error('render-node request failed');
	const data = await res.json();
	if (!data || typeof data.html !== 'string')
		throw new Error('render-node returned no html');
	return data.html;
}

async function uploadImage(file) {
	const fd = new FormData();
	fd.append('upload', file);
	const res = await fetch(PATH + 'model/PageBuilder/files/upload.php', {
		method: 'POST',
		credentials: 'include',
		body: fd,
	});
	if (!res.ok) {
		let msg = 'Upload failed';
		try {
			const data = await res.json();
			if (data && data.error && data.error.message)
				msg = data.error.message;
		} catch (e) {
		}
		throw new Error(msg);
	}
	const data = await res.json();
	if (!data || typeof data.url !== 'string')
		throw new Error('Upload returned no URL');
	return data.url;
}

async function searchSource(source, query) {
	const params = new URLSearchParams({
		source,
		q: query || '',
		limit: '10',
	});
	const res = await fetch(PATH + 'page-builder/search?' + params.toString(), {
		credentials: 'include',
	});
	if (!res.ok)
		return [];
	const data = await res.json();
	return data && Array.isArray(data.items) ? data.items : [];
}

// Full item list for a (non-searchable) source's picker dropdown. Decoupled from
// sample-data (which stays capped by sample-data-limit) — the dropdown offers all
// items.
async function listSource(source) {
	const params = new URLSearchParams({ source });
	const res = await fetch(PATH + 'page-builder/list-items?' + params.toString(), {
		credentials: 'include',
	});
	if (!res.ok)
		return [];
	const data = await res.json();
	return data && Array.isArray(data.items) ? data.items : [];
}

async function resolveItems(source, ids) {
	const clean = Array.isArray(ids) ? ids.filter(id => id !== null && typeof id !== 'undefined') : [];
	if (!clean.length)
		return [];
	const params = new URLSearchParams({
		source,
		ids: clean.join(','),
	});
	const res = await fetch(PATH + 'page-builder/resolve-items?' + params.toString(), {
		credentials: 'include',
	});
	if (!res.ok)
		return [];
	const data = await res.json();
	return data && Array.isArray(data.items) ? data.items : [];
}

async function saveFragment(payload) {
	const res = await fetch(PATH + 'page-builder/fragments', {
		method: 'POST',
		credentials: 'include',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(payload),
	});
	if (!res.ok)
		throw new Error('fragment save failed');
	const data = await res.json();
	if (!data || typeof data.id !== 'string')
		throw new Error('fragment save returned no id');
	fragmentsPromise = null;
	return { id: data.id };
}

// Fragment-definition editing: the `doc` field declares `data-pb-scope-source-field`
// naming the sibling field that holds the fragment's declared data source. Read its
// value so the editor mounts with that root scope source (its field/chip pickers
// then offer the source's fields while authoring the fragment body).
function resolveScopeSourceEl(textarea) {
	const fieldName = textarea.getAttribute('data-pb-scope-source-field');
	if (!fieldName)
		return null;
	const form = textarea.closest('form') || document;
	// ModEl renders a field input named by its key (possibly wrapped, e.g.
	// `name[source]`); match the exact name first, then a bracketed suffix.
	return form.querySelector('[name="' + fieldName + '"]')
		|| form.querySelector('[name$="[' + fieldName + ']"]');
}

function remountPageBuilder(textarea) {
	const attached = textarea.getAttribute('data-pb-attached');
	if (attached !== null && attached !== 'attaching') {
		const idx = parseInt(attached);
		const inst = pbInstancesArr[idx];
		if (inst && typeof inst.destroy === 'function') {
			try { inst.destroy(); } catch (e) {}
		}
		pbInstancesArr[idx] = undefined;
	}
	const wrapper = textarea.previousElementSibling;
	if (wrapper && wrapper.classList && wrapper.classList.contains('pagebuilder_wrapper'))
		wrapper.remove();
	textarea.style.display = '';
	textarea.removeAttribute('data-pb-attached');
	checkPageBuilder();
}

async function checkPageBuilder() {
	if (typeof window.PageBuilder !== 'function') {
		console.warn('[page-builder] window.PageBuilder is not loaded yet');
		return;
	}

	const elements = document.querySelectorAll('.pagebuilder_field');
	for (const textarea of elements) {
		if (textarea.offsetParent === null || textarea.getAttribute('data-pb-attached') !== null)
			continue;

		textarea.setAttribute('data-pb-attached', 'attaching');

		const wrapper = document.createElement('div');
		wrapper.className = 'pagebuilder_wrapper';
		textarea.parentNode.insertBefore(wrapper, textarea);
		textarea.style.display = 'none';

		const languages = parseLanguages(textarea);
		const value = parseDoc(textarea.value);

		// Register host custom components before mounting (registry is global/shared).
		const customComponents = parseComponents(textarea);
		if (customComponents)
			registerCustomComponents(customComponents);

		// Merge editor-preview sample data into the configured descriptors (if any),
		// so dynamic content previews with real values. Falls back to descriptors
		// without sample (chips still insert; preview just lacks live values).
		const dataSources = parseDataSources(textarea);
		if (dataSources) {
			try {
				const sampleMap = await fetchSampleData();
				for (const src of dataSources) {
					if (src && sampleMap[src.key])
						src.sample = sampleMap[src.key];
				}
			} catch (e) {
			}
		}

		let fragments = [];
		try {
			fragments = await fetchFragments();
		} catch (e) {
		}

		// Root scope source (only when this field edits a fragment definition). Re-mount
		// the editor when the sibling source field changes, so the new scope takes effect.
		const scopeSourceEl = resolveScopeSourceEl(textarea);
		const scopeSource = scopeSourceEl && typeof scopeSourceEl.value === 'string' && scopeSourceEl.value !== ''
			? scopeSourceEl.value
			: null;
		if (scopeSourceEl && scopeSourceEl.getAttribute('data-pb-scope-listener') === null) {
			scopeSourceEl.setAttribute('data-pb-scope-listener', '1');
			scopeSourceEl.addEventListener('change', () => remountPageBuilder(textarea));
		}

		const options = {
			value,
			languages,
			defaultLanguage: languages[0],
			onChange: (json) => {
				textarea.value = JSON.stringify(json);
				triggerOnChange(textarea);
			},
			onUploadImage: uploadImage,
			onSearchSource: searchSource,
			onListSource: listSource,
			onResolveItems: resolveItems,
			fragments,
			onSaveFragment: saveFragment,
		};
		if (dataSources)
			options.dataSources = dataSources;
		if (scopeSource)
			options.scopeSource = scopeSource;
		if (customComponents)
			options.onRenderComponent = renderComponentNode;

		const instance = new window.PageBuilder(wrapper, options);

		const index = pbInstancesArr.push(instance) - 1;
		textarea.setAttribute('data-pb-attached', String(index));
		textarea.setAttribute('data-getvalue-function', 'getPageBuilderValue');
		textarea.setAttribute('data-setvalue-function', 'setPageBuilderValue');
	}
}

function getPageBuilderInstance(index = 0) {
	if (typeof pbInstancesArr[index] === 'undefined')
		return null;
	return pbInstancesArr[index];
}

function getPageBuilderValue() {
	const attached = this.getAttribute('data-pb-attached');
	if (attached === null || attached === 'attaching')
		return this.value;
	const index = parseInt(attached);
	if (typeof pbInstancesArr[index] === 'undefined')
		return this.value;
	return JSON.stringify(pbInstancesArr[index].getValue());
}

function setPageBuilderValue(v) {
	const attached = this.getAttribute('data-pb-attached');
	if (attached === null || attached === 'attaching') {
		this.value = typeof v === 'string' ? v : JSON.stringify(v);
		return true;
	}
	const index = parseInt(attached);
	if (typeof pbInstancesArr[index] === 'undefined')
		return false;
	const doc = typeof v === 'string' ? parseDoc(v) : (v || JSON.parse(EMPTY_DOC_JSON));
	pbInstancesArr[index].setValue(doc);
	return true;
}

// Loaded as a classic <script> via the assets pipeline (no ES module / type=
// "module"), so expose the form-glue functions on window. ModEl's form save wires
// getPageBuilderValue/setPageBuilderValue by name via data-getvalue-function/
// data-setvalue-function (this = the textarea at call time).
window.checkPageBuilder = checkPageBuilder;
window.getPageBuilderValue = getPageBuilderValue;
window.setPageBuilderValue = setPageBuilderValue;
window.getPageBuilderInstance = getPageBuilderInstance;

window.addEventListener('load', function () {
	onHtmlChange(checkPageBuilder);
});

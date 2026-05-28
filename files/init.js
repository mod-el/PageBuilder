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

export async function checkPageBuilder() {
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

		const instance = new window.PageBuilder(wrapper, {
			value,
			languages,
			defaultLanguage: languages[0],
			onChange: (json) => {
				textarea.value = JSON.stringify(json);
				triggerOnChange(textarea);
			},
			onUploadImage: uploadImage,
		});

		const index = pbInstancesArr.push(instance) - 1;
		textarea.setAttribute('data-pb-attached', String(index));
		textarea.setAttribute('data-getvalue-function', 'getPageBuilderValue');
		textarea.setAttribute('data-setvalue-function', 'setPageBuilderValue');
	}
}

export function getPageBuilderInstance(index = 0) {
	if (typeof pbInstancesArr[index] === 'undefined')
		return null;
	return pbInstancesArr[index];
}

export function getPageBuilderValue() {
	const attached = this.getAttribute('data-pb-attached');
	if (attached === null || attached === 'attaching')
		return this.value;
	const index = parseInt(attached);
	if (typeof pbInstancesArr[index] === 'undefined')
		return this.value;
	return JSON.stringify(pbInstancesArr[index].getValue());
}

export function setPageBuilderValue(v) {
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

window.addEventListener('load', function () {
	onHtmlChange(checkPageBuilder);
});

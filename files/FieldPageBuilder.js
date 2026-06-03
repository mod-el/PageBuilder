class FieldPageBuilder extends Field {
	constructor(name, options = {}) {
		super(name, options);
	}

	getSingleNode(lang = null) {
		let node = document.createElement('textarea');

		let attributes = this.options['attributes'];

		if (attributes.hasOwnProperty('class'))
			attributes['class'] += ' pagebuilder_field';
		else
			attributes['class'] = 'pagebuilder_field';

		super.assignAttributes(node, attributes);
		super.assignEvents(node, attributes, lang);

		return node;
	}
}

if (formSignatures)
	formSignatures.set('page-builder', FieldPageBuilder);

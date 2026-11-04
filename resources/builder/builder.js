/* Rulecraft Builder: dependency-free, instance-scoped editor. */
(function () {
    'use strict';
    function element(tag, attributes, children) {
        const node = document.createElement(tag);
        Object.entries(attributes || {}).forEach(([key, value]) => {
            if (value === null || value === undefined) return;
            if (key === 'class') node.className = value;
            else if (key === 'value') node.value = value;
            else if (key === 'checked') node.checked = value;
            else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
            else node.setAttribute(key, value);
        });
        (children || []).forEach((child) => {
            if (child !== null)
                node.append(child instanceof Node ? child : document.createTextNode(child));
        });
        return node;
    }
    function group(name) {
        return { id: null, name: name || '', match: 'all', enabled: true, rules: [], groups: [] };
    }
    function rule() {
        return {
            id: null,
            name: '',
            expression: '',
            failure_message: null,
            success_message: null,
            enabled: true,
            priority: 0,
            action: null,
            action_parameters: null,
            action_parameters_json: 'null',
            builder_definition: null,
        };
    }
    class Editor {
        constructor(root) {
            this.root = root;
            this.settings = JSON.parse(root.dataset.settings);
            this.settings.functions = this.settings.functions || {};
            this.draft = group();
            this.revision = null;
            this.context = '{}';
            this.previewResult = null;
            this.previewFresh = false;
            this.dirty = false;
            this.busy = false;
            this.loaded = !this.settings.group;
            this.message = '';
            this.messageType = 'info';
            this.sequence = 0;
            this.ruleNodes = new WeakMap();
            this.expandedPanels = new WeakMap();
            this.render();
            if (this.settings.group) this.load();
        }
        async request(url, data) {
            const headers = { Accept: 'application/json' };
            const options = { credentials: 'same-origin', headers };
            if (data) {
                options.method = 'POST';
                headers['Content-Type'] = 'application/json';
                headers['X-CSRF-TOKEN'] = this.settings.csrf;
                options.body = JSON.stringify(data);
            }
            const response = await fetch(url, options);
            let body;
            try {
                body = await response.json();
            } catch (error) {
                throw new Error('The server did not return JSON. Check your session and routes.');
            }
            if (!response.ok)
                throw new Error(body.message || 'The request failed (' + response.status + ').');
            return body;
        }
        async load() {
            this.busy = true;
            this.render();
            try {
                const url = new URL(this.settings.load, window.location.href);
                url.searchParams.set('group', this.settings.group);
                const body = await this.request(url);
                this.draft = body.group;
                this.revision = body.revision;
                this.loaded = true;
                this.message = '';
            } catch (error) {
                this.message = error.message;
                this.messageType = 'error';
            } finally {
                this.busy = false;
                this.render();
            }
        }
        dirtyState() {
            this.dirty = true;
            this.previewFresh = false;
            if (this.stateNode && !this.busy) this.stateNode.textContent = 'Unsaved changes';
            if (this.previewState && this.previewResult)
                this.previewState.textContent = 'Preview is out of date';
        }
        async perform(mode) {
            if (this.busy || !this.loaded) return;
            this.busy = true;
            this.message = '';
            this.render();
            try {
                const payload = { group: this.draft };
                if (mode === 'save') payload.revision = this.revision;
                if (mode === 'preview') payload.context_json = this.context;
                const body = await this.request(this.settings[mode], payload);
                if (mode === 'preview') {
                    this.previewResult = body.result;
                    this.previewFresh = true;
                } else this.draft = body.group;
                if (mode === 'save') {
                    this.revision = body.revision;
                    this.dirty = false;
                }
                this.message =
                    mode === 'save'
                        ? 'Policy saved.'
                        : mode === 'validate'
                          ? 'The policy is valid.'
                          : 'Preview completed. No actions were run.';
                this.messageType = 'success';
            } catch (error) {
                this.message = error.message;
                this.messageType = 'error';
            } finally {
                this.busy = false;
                this.render();
                if (this.messageType === 'error') this.messageNode.focus();
            }
        }
        button(text, callback, kind) {
            const node = element(
                'button',
                {
                    type: 'button',
                    id: this.root.id + '-button-' + ++this.sequence,
                    class:
                        'rc-button btn ' +
                        (kind === 'primary'
                            ? 'rc-button-primary btn-primary'
                            : 'btn-outline-secondary'),
                    onclick: callback,
                },
                [text],
            );
            node.disabled = this.busy;
            return node;
        }
        field(label, control, hint) {
            const id = this.root.id + '-field-' + ++this.sequence;
            control.id = id;
            const children = [
                element('label', { for: id, class: 'rc-label form-label' }, [label]),
                control,
            ];
            if (hint) {
                const help = element('small', { id: id + '-help', class: 'rc-help' }, [hint]);
                control.setAttribute('aria-describedby', help.id);
                children.push(help);
            }
            return element('div', { class: 'rc-field' }, children);
        }
        input(value, change, attributes) {
            return element(
                'input',
                Object.assign(
                    {
                        class: 'rc-input form-control',
                        type: 'text',
                        value,
                        oninput: (event) => {
                            change(event.target.value);
                            this.dirtyState();
                        },
                    },
                    attributes,
                ),
            );
        }
        select(options, value, change) {
            const select = element('select', {
                class: 'rc-input form-select',
                onchange: (event) => {
                    change(event.target.value);
                    this.dirtyState();
                },
            });
            options.forEach(([key, label]) => {
                const option = element('option', { value: key }, [label]);
                option.selected = String(value) === String(key);
                select.append(option);
            });
            return select;
        }
        removeButton(label, callback) {
            const button = this.button('', callback);
            button.className = 'rc-button rc-button-danger rc-icon-button btn';
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '1.75');
            svg.setAttribute('stroke-linecap', 'round');
            svg.setAttribute('stroke-linejoin', 'round');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('focusable', 'false');
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', 'M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6');
            svg.append(path);
            button.append(svg);
            return button;
        }
        toggle(node) {
            return element('label', { class: 'rc-toggle' }, [
                element('input', {
                    type: 'checkbox',
                    checked: node.enabled,
                    class: 'rc-checkbox form-check-input',
                    onchange: (event) => {
                        node.enabled = event.target.checked;
                        this.dirtyState();
                    },
                }),
                element('span', {}, ['Enabled']),
            ]);
        }
        nodeHeader(title, node, remove) {
            return element('div', { class: 'rc-node-header' }, [
                element('div', { class: 'rc-node-heading' }, [
                    element('h3', { class: 'rc-node-title' }, [title]),
                    this.toggle(node),
                ]),
                remove || null,
            ]);
        }
        defaultDefinition(fieldName) {
            const spec = this.settings.fields[fieldName] || { type: 'string' };
            const operand = this.defaultValue(spec.type);
            if (spec.options && spec.options.length) {
                operand.value = ['integer', 'number'].includes(spec.type)
                    ? String(spec.options[0])
                    : spec.options[0];
            }
            return {
                source: { type: 'field', field: fieldName || '', value_type: spec.type },
                operator: '==',
                operand,
            };
        }
        operators(type) {
            const operators = ['==', '!=', '===', '!==', 'in', 'not in'];
            return ['integer', 'number'].includes(type)
                ? operators.concat(['<', '<=', '>', '>='])
                : operators;
        }
        defaultValue(type) {
            return {
                type: 'value',
                value_type: type,
                value: { string: '', integer: '0', number: '0', boolean: false }[type],
            };
        }
        functionDefinition(name) {
            const spec = this.settings.functions[name];
            const args = {};
            Object.keys(spec.arguments).forEach((key) => {
                args[key] = { type: 'field', field: key };
            });
            return {
                source: { type: 'function', name, arguments: args, return_type: spec.return_type },
                operator: '==',
                operand: this.defaultValue(spec.return_type),
            };
        }
        functionInputs(container, source, change, choices, compared = false) {
            const functions = this.settings.functions;
            container.append(
                this.field(
                    compared ? 'Compared function' : 'Function',
                    this.select(
                        (choices || Object.entries(functions)).map(([key, spec]) => [
                            key,
                            spec.label,
                        ]),
                        source.name,
                        (name) => {
                            const replacement = this.functionDefinition(name);
                            change(replacement);
                            this.render();
                        },
                    ),
                ),
            );
            const spec = functions[source.name];
            const argumentsText = Object.entries(spec.arguments).map(([name, argument]) => {
                const value = source.arguments[name];
                const text =
                    value.type === 'field'
                        ? value.field
                        : value.value_type === 'string'
                          ? JSON.stringify(value.value)
                          : String(value.value);
                return this.signatureType(argument.type) + ' ' + text;
            });
            container.append(
                element('code', { class: 'rc-function-signature rc-code' }, [
                    source.name +
                        '(' +
                        argumentsText.join(', ') +
                        '): ' +
                        this.signatureType(source.return_type),
                ]),
            );
        }
        signatureType(type) {
            return { integer: 'int', number: 'int|float', boolean: 'bool', string: 'string' }[type];
        }
        async toCode(node) {
            this.busy = true;
            this.render();
            try {
                const probe = group('expression-preview');
                const leaf = rule();
                leaf.name = 'expression-preview';
                leaf.builder_definition = node.builder_definition;
                probe.rules = [leaf];
                const response = await this.request(this.settings.validate, { group: probe });
                node.expression = response.group.rules[0].expression;
                node.builder_definition = null;
                this.dirtyState();
            } catch (error) {
                this.message = error.message;
                this.messageType = 'error';
            } finally {
                this.busy = false;
                this.render();
            }
        }
        ruleCard(node, parent, index) {
            const card = element('fieldset', { class: 'rc-rule' });
            this.ruleNodes.set(node, card);
            const title = node.name.trim() || 'Unnamed rule';
            card.append(
                element('legend', { class: 'rc-legend' }, [title]),
                this.nodeHeader(
                    title,
                    node,
                    this.removeButton('Remove rule', () => {
                        parent.rules.splice(index, 1);
                        this.dirtyState();
                        this.render();
                    }),
                ),
            );
            const modes = [
                ['visual', 'Visual'],
                ['expression', 'Expression'],
            ];
            const names = Object.keys(this.settings.fields);
            const mode = this.select(
                modes,
                node.builder_definition ? 'visual' : 'expression',
                (value) => {
                    if (value === 'expression') this.toCode(node);
                    else {
                        if (
                            node.expression &&
                            !window.confirm('Replace this expression with a new visual condition?')
                        ) {
                            mode.value = 'expression';
                            return;
                        }
                        node.builder_definition = this.defaultDefinition(names[0]);
                        this.render();
                    }
                },
            );
            card.append(
                element('section', { class: 'rc-rule-details' }, [
                    element('div', { class: 'rc-section-title' }, ['Rule details']),
                    element('div', { class: 'rc-rule-top' }, [
                        this.field(
                            'Rule name',
                            this.input(
                                node.name,
                                (value) => {
                                    node.name = value;
                                    const title = value.trim() || 'Unnamed rule';
                                    card.querySelector('.rc-node-title').textContent = title;
                                    card.querySelector('.rc-legend').textContent = title;
                                },
                                { maxlength: 191 },
                            ),
                        ),
                        this.field(
                            'Priority',
                            this.input(
                                node.priority,
                                (value) => {
                                    node.priority = Number(value);
                                },
                                { type: 'number', step: 1, min: -2147483648, max: 2147483647 },
                            ),
                        ),
                        this.field('Editor', mode),
                    ]),
                ]),
            );
            if (node.builder_definition) card.append(this.visual(node));
            else
                card.append(
                    this.field(
                        'Expression',
                        element('textarea', {
                            class: 'rc-input rc-code form-control',
                            rows: 2,
                            value: node.expression,
                            spellcheck: 'false',
                            oninput: (event) => {
                                node.expression = event.target.value;
                                this.dirtyState();
                            },
                        }),
                        'Exact Symfony expression text is preserved. Variables have no $.',
                    ),
                );
            const messages = this.disclosure(node, 'messages', 'Messages');
            const messageSummary = () => {
                const count = ['failure_message', 'success_message'].filter(
                    (field) => (node[field] || '').trim() !== '',
                ).length;
                return ' — ' + (count ? count + ' customized' : 'Defaults');
            };
            const messageState = element('span', { class: 'rc-state' }, [messageSummary()]);
            messages.querySelector('summary').append(messageState);
            messages.append(
                this.field(
                    'Failure message (optional)',
                    element('textarea', {
                        class: 'rc-input form-control',
                        rows: 2,
                        value: node.failure_message || '',
                        oninput: (event) => {
                            node.failure_message = event.target.value || null;
                            messageState.textContent = messageSummary();
                            this.dirtyState();
                        },
                    }),
                    'Shown when this condition is false. Leave blank for the default message.',
                ),
                this.field(
                    'Success message (optional)',
                    element('textarea', {
                        class: 'rc-input form-control',
                        rows: 2,
                        value: node.success_message || '',
                        oninput: (event) => {
                            node.success_message = event.target.value || null;
                            messageState.textContent = messageSummary();
                            this.dirtyState();
                        },
                    }),
                    'Shown when this condition passes. Leave blank for no success message.',
                ),
            );
            card.append(messages);
            const action = this.disclosure(node, 'action', 'Action');
            const actionState = element('span', { class: 'rc-state' }, [
                ' — ' + (node.action || 'None selected'),
            ]);
            action.querySelector('summary').append(actionState);
            action.append(
                this.field(
                    'Action name',
                    this.input(
                        node.action || '',
                        (value) => {
                            node.action = value || null;
                            actionState.textContent = ' — ' + (node.action || 'None selected');
                        },
                        { list: this.root.id + '-actions', maxlength: 191 },
                    ),
                    'Handlers never run in preview.',
                ),
            );
            const parameters =
                node.action_parameters_json === undefined
                    ? JSON.stringify(node.action_parameters, null, 2)
                    : node.action_parameters_json;
            action.append(
                this.field(
                    'Action parameters (JSON)',
                    element('textarea', {
                        class: 'rc-input rc-code form-control',
                        rows: 3,
                        value: parameters,
                        oninput: (event) => {
                            node.action_parameters_json = event.target.value;
                            this.dirtyState();
                        },
                    }),
                    'A JSON object, array, or null.',
                ),
            );
            card.append(action);
            return card;
        }
        disclosure(node, panel, label) {
            const expanded = this.expandedPanels.get(node);
            const panelClass = {
                action: 'rc-advanced',
                expression: 'rc-expression-preview',
                messages: 'rc-rule-messages',
            }[panel];
            const details = element(
                'details',
                {
                    class: 'rc-disclosure ' + panelClass,
                    'data-panel': panel,
                    open: expanded && expanded.has(panel) ? '' : null,
                },
                [element('summary', {}, [label])],
            );
            details.rulecraftNode = node;
            return details;
        }
        typeBadge(controls, type) {
            controls.classList.add('rc-with-type');
            const badge = element('span', { class: 'rc-type-info', title: 'Type: ' + type }, [
                type.charAt(0).toUpperCase() + type.slice(1),
            ]);
            controls.append(badge);
        }
        operandRow(label, extraClass = '') {
            const controls = element('div', { class: 'rc-operand-controls ' + extraClass });
            const section = element('div', { class: 'rc-condition-row' }, [
                element('div', { class: 'rc-condition-label' }, [label]),
                controls,
            ]);
            return { section, controls };
        }
        operatorLabel(operator) {
            const label = {
                '==': 'Equals',
                '!=': 'Does not equal',
                '===': 'Strictly equals',
                '!==': 'Does not strictly equal',
                '<': 'Less than',
                '<=': 'Less than or equal',
                '>': 'Greater than',
                '>=': 'Greater than or equal',
                in: 'Is in list',
                'not in': 'Is not in list',
            }[operator];
            return label + ' (' + operator + ')';
        }
        visual(node) {
            const definition = node.builder_definition;
            const source = definition.source;
            const isFunction = source.type === 'function';
            const fieldName = isFunction ? null : source.field;
            const configured = this.settings.fields[fieldName];
            const type = isFunction
                ? source.return_type
                : configured
                  ? configured.type
                  : source.value_type;
            const spec =
                configured && !isFunction ? configured : { type, operators: this.operators(type) };
            const container = element('div', { class: 'rc-visual' });
            const sourceRow = this.operandRow('Source', 'rc-source-controls');
            const sourceControls = sourceRow.controls;
            const sourceKinds = [['field', 'Field']];
            if (Object.keys(this.settings.functions).length) {
                sourceKinds.push(['function', 'Function']);
            }
            sourceControls.append(
                this.field(
                    'Source',
                    this.select(sourceKinds, isFunction ? 'function' : 'field', (value) => {
                        node.builder_definition =
                            value === 'function'
                                ? this.functionDefinition(Object.keys(this.settings.functions)[0])
                                : this.defaultDefinition(Object.keys(this.settings.fields)[0]);
                        this.render();
                    }),
                ),
            );
            container.append(sourceRow.section);
            if (isFunction) {
                this.functionInputs(sourceControls, source, (replacement) => {
                    node.builder_definition = replacement;
                });
            } else if (configured) {
                sourceControls.append(
                    this.field(
                        'Field',
                        this.select(
                            Object.entries(this.settings.fields).map(([key, field]) => [
                                key,
                                field.label,
                            ]),
                            fieldName,
                            (value) => {
                                node.builder_definition = this.defaultDefinition(value);
                                this.render();
                            },
                        ),
                    ),
                );
            } else {
                sourceControls.append(
                    this.field(
                        'Field',
                        this.input(
                            source.field,
                            (value) => {
                                source.field = value;
                            },
                            { placeholder: 'loan_amount' },
                        ),
                    ),
                );
                sourceControls.classList.add('rc-with-type');
                sourceControls.append(
                    this.field(
                        'Field type',
                        this.select(
                            ['string', 'integer', 'number', 'boolean'].map((value) => [
                                value,
                                value,
                            ]),
                            source.value_type,
                            (value) => {
                                source.value_type = value;
                                definition.operator = '==';
                                definition.operand = this.defaultValue(value);
                                this.render();
                            },
                        ),
                    ),
                );
            }
            if (configured && !isFunction) this.typeBadge(sourceControls, spec.type);
            container.append(
                this.field(
                    'Operator',
                    this.select(
                        spec.operators.map((op) => [op, this.operatorLabel(op)]),
                        definition.operator,
                        (value) => {
                            definition.operator = value;
                            if (
                                !['in', 'not in'].includes(value) &&
                                definition.operand.type === 'function'
                            ) {
                                this.render();
                                return;
                            }
                            definition.operand = {
                                type: 'value',
                                value_type: spec.type,
                                value: ['in', 'not in'].includes(value)
                                    ? []
                                    : this.defaultValue(spec.type).value,
                            };
                            this.render();
                        },
                    ),
                ),
            );
            const membership = ['in', 'not in'].includes(definition.operator);
            const operand = definition.operand;
            const kinds = [['value', membership ? 'List' : 'Value']];
            const functions = this.compatibleFunctions(spec);
            if (!membership) {
                kinds.push(['field', 'Field']);
                if (functions.length) kinds.push(['function', 'Function']);
            }
            if (['==', '!=', '===', '!=='].includes(definition.operator))
                kinds.push(['null', 'Null']);
            const kind = operand.value_type === 'null' ? 'null' : operand.type;
            const comparedRow = this.operandRow('Compare with');
            const comparedControls = comparedRow.controls;
            container.append(comparedRow.section);
            comparedControls.append(
                this.field(
                    'Compare with',
                    this.select(kinds, kind, (value) => {
                        if (value === 'field') {
                            const compatible = this.compatibleFields(spec);
                            definition.operand = {
                                type: 'field',
                                field: compatible.length ? compatible[0][0] : '',
                            };
                        } else if (value === 'function') {
                            definition.operand = this.functionDefinition(functions[0][0]).source;
                        } else if (value === 'null')
                            definition.operand = { type: 'value', value_type: 'null', value: null };
                        else
                            definition.operand = {
                                type: 'value',
                                value_type: spec.type,
                                value: membership ? [] : this.defaultValue(spec.type).value,
                            };
                        this.render();
                    }),
                ),
            );
            if (operand.type === 'function') {
                this.functionInputs(
                    comparedControls,
                    operand,
                    (replacement) => {
                        definition.operand = replacement.source;
                    },
                    functions,
                    true,
                );
            } else if (operand.type === 'field') {
                comparedControls.append(
                    this.field(
                        'Compared field',
                        this.compatibleFields(spec).length
                            ? this.select(this.compatibleFields(spec), operand.field, (value) => {
                                  operand.field = value;
                              })
                            : this.input(operand.field, (value) => {
                                  operand.field = value;
                              }),
                    ),
                );
            } else if (kind !== 'null') {
                if (membership) {
                    const display = !Array.isArray(operand.value)
                        ? String(operand.value)
                        : operand.value_type === 'integer' || operand.value_type === 'number'
                          ? '[' + operand.value.join(', ') + ']'
                          : JSON.stringify(operand.value);
                    const control = element('textarea', {
                        class: 'rc-input rc-code form-control',
                        rows: 2,
                        value: display,
                        oninput: (event) => {
                            // Numeric lists remain strings to preserve integer/decimal precision.
                            const text = event.target.value.trim();
                            if (['integer', 'number'].includes(operand.value_type)) {
                                try {
                                    const parsed = JSON.parse(text);
                                    operand.value =
                                        Array.isArray(parsed) &&
                                        parsed.every((v) => typeof v === 'number')
                                            ? parsed.length
                                                ? text
                                                      .slice(1, -1)
                                                      .split(',')
                                                      .map((v) => v.trim())
                                                : []
                                            : text;
                                } catch (error) {
                                    operand.value = text;
                                }
                            } else {
                                try {
                                    operand.value = JSON.parse(text);
                                } catch (error) {
                                    operand.value = text;
                                }
                            }
                            this.dirtyState();
                        },
                    });
                    comparedControls.append(
                        this.field('Values (JSON array)', control, 'For example: [2, 5, 8]'),
                    );
                } else if (spec.options) {
                    const options = spec.options.map((value, index) => [
                        String(index),
                        String(value),
                    ]);
                    const selected = spec.options.findIndex(
                        (value) => String(value) === String(operand.value),
                    );
                    comparedControls.append(
                        this.field(
                            'Value',
                            this.select(options, String(selected), (value) => {
                                operand.value = spec.options[Number(value)];
                                if (['integer', 'number'].includes(operand.value_type))
                                    operand.value = String(operand.value);
                            }),
                        ),
                    );
                } else if (spec.type === 'boolean') {
                    comparedControls.append(
                        this.field(
                            'Value',
                            this.select(
                                [
                                    ['true', 'True'],
                                    ['false', 'False'],
                                ],
                                String(operand.value),
                                (value) => {
                                    operand.value = value === 'true';
                                },
                            ),
                        ),
                    );
                } else {
                    comparedControls.append(
                        this.field(
                            'Value',
                            this.input(
                                operand.value,
                                (value) => {
                                    operand.value = value;
                                },
                                { inputmode: spec.type === 'string' ? 'text' : 'decimal' },
                            ),
                        ),
                    );
                }
                if (spec.type === 'number') {
                    comparedControls.classList.add('rc-with-type');
                    comparedControls.append(
                        this.field(
                            'Number type',
                            this.select(
                                [
                                    ['integer', 'Integer'],
                                    ['number', 'Decimal'],
                                ],
                                operand.value_type,
                                (value) => {
                                    operand.value_type = value;
                                },
                            ),
                        ),
                    );
                }
            }
            const expression = this.disclosure(node, 'expression', 'Generated expression');
            expression.append(
                element('div', { class: 'rc-expression' }, [
                    element('span', { class: 'rc-help' }, ['Last validated expression']),
                    element('code', {}, [
                        node.expression || 'Validate to generate the expression.',
                    ]),
                ]),
            );
            container.append(expression);
            return container;
        }
        compatibleFunctions(spec) {
            return Object.entries(this.settings.functions).filter(
                ([, fn]) =>
                    fn.return_type === spec.type ||
                    (['integer', 'number'].includes(spec.type) &&
                        ['integer', 'number'].includes(fn.return_type)),
            );
        }
        compatibleFields(spec) {
            return Object.entries(this.settings.fields)
                .filter(
                    ([, field]) =>
                        field.type === spec.type ||
                        (['integer', 'number'].includes(spec.type) &&
                            ['integer', 'number'].includes(field.type)),
                )
                .map(([key, field]) => [key, field.label]);
        }
        groupCard(node, parent, index, depth) {
            const card = element('fieldset', { class: 'rc-group', 'data-depth': depth });
            const title = depth === 1 ? 'Root group' : 'Condition group';
            const remove = parent
                ? this.removeButton('Remove group', () => {
                      if (
                          (node.rules.length || node.groups.length) &&
                          !window.confirm('Remove this group and its contents?')
                      )
                          return;
                      parent.groups.splice(index, 1);
                      this.dirtyState();
                      this.render();
                  })
                : null;
            card.append(
                element('legend', { class: 'rc-legend' }, [title]),
                this.nodeHeader(title, node, remove),
            );
            const row = element('div', { class: 'rc-group-top' }, [
                this.field(
                    'Group name',
                    this.input(
                        node.name,
                        (value) => {
                            node.name = value;
                        },
                        { maxlength: 191 },
                    ),
                ),
                this.field(
                    'Match',
                    this.select(
                        [
                            ['all', 'ALL · every condition'],
                            ['any', 'ANY · one condition'],
                        ],
                        node.match,
                        (value) => {
                            node.match = value;
                        },
                    ),
                ),
            ]);
            card.append(row);
            node.rules.forEach((leaf, position) =>
                card.append(this.ruleCard(leaf, node, position)),
            );
            node.groups.forEach((child, position) =>
                card.append(this.groupCard(child, node, position, depth + 1)),
            );
            if (!node.rules.length && !node.groups.length)
                card.append(
                    element('p', { class: 'rc-empty' }, [
                        'No conditions yet. Add a rule or a child group. Empty groups do not pass.',
                    ]),
                );
            const actions = element('div', { class: 'rc-group-actions' }, [
                this.button('+ Add condition', () => {
                    const leaf = rule();
                    let number = 1;
                    while (node.rules.some((item) => item.name === 'rule-' + number)) number++;
                    leaf.name = 'rule-' + number;
                    const first = Object.keys(this.settings.fields)[0];
                    leaf.builder_definition = this.defaultDefinition(first);
                    node.rules.push(leaf);
                    this.dirtyState();
                    this.render();
                    const nameInput = this.ruleNodes
                        .get(leaf)
                        .querySelector('.rc-rule-details input');
                    nameInput.focus();
                    nameInput.select();
                }),
            ]);
            if (depth < this.settings.maxDepth)
                actions.append(
                    this.button('+ Add group', () => {
                        node.groups.push(group());
                        this.dirtyState();
                        this.render();
                    }),
                );
            card.append(actions);
            return card;
        }
        resultTree(result) {
            const status = result.disabled
                ? 'DISABLED'
                : result.has_errors
                  ? 'ERROR'
                  : result.passed
                    ? 'PASS'
                    : 'FAIL';
            const list = element('ul', { class: 'rc-results' }, [
                element('li', {}, [
                    element('span', { class: 'rc-badge rc-status-' + status.toLowerCase() }, [
                        status,
                    ]),
                    ' ' + result.name,
                ]),
            ]);
            const message = result.passed ? result.passed_message : result.failed_message;
            if (message) {
                list.append(element('li', { class: 'rc-group-message rc-help' }, [message]));
            }
            Object.entries(result.validation_errors || {}).forEach(([field, messages]) => {
                list.append(
                    element('li', { class: 'rc-error' }, [field + ': ' + messages.join(' ')]),
                );
            });
            result.rules.forEach((leaf) => {
                const state = leaf.disabled
                    ? 'DISABLED'
                    : leaf.error
                      ? 'ERROR'
                      : leaf.passed
                        ? 'PASS'
                        : 'FAIL';
                list.append(
                    element('li', {}, [
                        element(
                            'span',
                            {
                                class: 'rc-badge rc-status-' + state.toLowerCase(),
                            },
                            [state],
                        ),
                        ' ' + leaf.name,
                        leaf.error ? element('p', { class: 'rc-error' }, [leaf.error]) : null,
                        leaf.message ? element('p', { class: 'rc-help' }, [leaf.message]) : null,
                    ]),
                );
            });
            result.groups.forEach((child) =>
                list.append(element('li', {}, [this.resultTree(child)])),
            );
            return list;
        }
        render() {
            this.root.querySelectorAll('.rc-disclosure').forEach((panel) => {
                const node = panel.rulecraftNode;
                if (!node) return;
                const expanded = this.expandedPanels.get(node) || new Set();
                if (panel.open) expanded.add(panel.dataset.panel);
                else expanded.delete(panel.dataset.panel);
                this.expandedPanels.set(node, expanded);
            });
            const active = document.activeElement;
            if (this.root.contains(active) && active.id) {
                this.pendingFocus = {
                    id: active.id,
                    tag: active.tagName,
                    text: active.tagName === 'BUTTON' ? active.textContent : null,
                };
            }
            this.sequence = 0;
            this.root.replaceChildren();
            this.root.setAttribute('aria-busy', String(this.busy));
            this.stateNode = element('span', { class: 'rc-state', role: 'status' }, [
                this.busy ? 'Working…' : this.dirty ? 'Unsaved changes' : 'Ready',
            ]);
            const header = element('header', { class: 'rc-header' }, [
                element('div', {}, [
                    element('p', { class: 'rc-eyebrow' }, ['RULECRAFT']),
                    element('h2', { class: 'rc-title' }, ['Rulecraft Builder']),
                    this.stateNode,
                ]),
            ]);
            if (this.loaded)
                header.append(
                    element('div', { class: 'rc-toolbar' }, [
                        this.button('Validate', () => this.perform('validate')),
                        this.button('Save policy', () => this.perform('save'), 'primary'),
                    ]),
                );
            this.root.append(header);
            this.messageNode = element(
                'div',
                {
                    class: 'rc-message rc-message-' + this.messageType,
                    role: this.messageType === 'error' ? 'alert' : 'status',
                    tabindex: -1,
                },
                [this.message],
            );
            if (!this.message) this.messageNode.hidden = true;
            this.root.append(this.messageNode);
            if (!this.loaded) {
                if (!this.busy) this.root.append(this.button('Retry loading', () => this.load()));
                return;
            }
            const editor = element('div', { class: 'rc-editor' }, [
                this.groupCard(this.draft, null, 0, 1),
            ]);
            const sidebar = element(
                'aside',
                { class: 'rc-preview', 'aria-label': 'Draft preview' },
                [
                    element('h3', {}, ['Test your conditions']),
                    element('p', { class: 'rc-help' }, [
                        'Preview with sample data. Saved records and action handlers stay untouched.',
                    ]),
                ],
            );
            sidebar.append(
                this.field(
                    'Context (JSON object)',
                    element('textarea', {
                        class: 'rc-input rc-code rc-context form-control',
                        rows: 10,
                        spellcheck: 'false',
                        value: this.context,
                        oninput: (event) => {
                            this.context = event.target.value;
                            this.previewFresh = false;
                            if (this.previewResult)
                                this.previewState.textContent = 'Preview is out of date';
                        },
                    }),
                ),
            );
            sidebar.append(this.button('Preview conditions', () => this.perform('preview')));
            this.previewState = element('p', { class: 'rc-help', role: 'status' }, [
                this.previewResult
                    ? this.previewFresh
                        ? 'Latest preview result'
                        : 'Preview is out of date'
                    : 'Results will appear here.',
            ]);
            sidebar.append(this.previewState);
            if (this.previewResult) sidebar.append(this.resultTree(this.previewResult));
            this.root.append(element('div', { class: 'rc-workspace' }, [editor, sidebar]));
            const actions = element('datalist', { id: this.root.id + '-actions' });
            this.settings.actions.forEach((name) =>
                actions.append(element('option', { value: name })),
            );
            this.root.append(actions);
            this.root.querySelectorAll('input,select,textarea').forEach((node) => {
                node.disabled = this.busy;
            });
            if (!this.busy && this.pendingFocus) {
                const previous = this.pendingFocus;
                const target = document.getElementById(previous.id);
                if (
                    target &&
                    this.root.contains(target) &&
                    target.tagName === previous.tag &&
                    (previous.text === null || target.textContent === previous.text)
                ) {
                    target.focus({ preventScroll: true });
                }
                this.pendingFocus = null;
            }
        }
    }
    function mount() {
        document.querySelectorAll('[data-rulecraft-builder]').forEach((root) => {
            if (!root.rulecraftEditor) root.rulecraftEditor = new Editor(root);
        });
    }
    window.RulecraftBuilder = { mount };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
})();

(function (blocks, element, blockEditor, components, i18n) {
  var el = element.createElement;
  var InspectorControls = blockEditor.InspectorControls;
  var PanelBody = components.PanelBody;
  var TextControl = components.TextControl;
  var SelectControl = components.SelectControl;
  var __ = i18n.__;

  blocks.registerBlockType('openbooking/booking-form', {
    edit: function (props) {
      var attrs = props.attributes;

      return el(
        'div',
        {},
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: __('Ajustes OpenBooking', 'openbooking-wp'), initialOpen: true },
            el(TextControl, {
              label: __('ID o slug de servicio', 'openbooking-wp'),
              value: attrs.service || '',
              help: __('Opcional. Dejalo vacio para mostrar todos los servicios disponibles.', 'openbooking-wp'),
              onChange: function (value) {
                props.setAttributes({ service: value });
              },
            }),
            el(SelectControl, {
              label: __('Layout', 'openbooking-wp'),
              value: attrs.layout || 'steps',
              options: [
                { label: __('Pasos', 'openbooking-wp'), value: 'steps' },
              ],
              onChange: function (value) {
                props.setAttributes({ layout: value });
              },
            }),
            el(TextControl, {
              label: __('Preset visual', 'openbooking-wp'),
              value: attrs.preset || '',
              help: __('Opcional. Usa un preset configurado en OpenBooking.', 'openbooking-wp'),
              onChange: function (value) {
                props.setAttributes({ preset: value });
              },
            })
          )
        ),
        el(
          'div',
          { className: 'obwp-block-preview' },
          el('strong', {}, __('Formulario de reservas OpenBooking', 'openbooking-wp')),
          el('p', {}, __('El formulario real se mostrara en el sitio publicado.', 'openbooking-wp')),
          el('code', {}, '[openbooking]')
        )
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);

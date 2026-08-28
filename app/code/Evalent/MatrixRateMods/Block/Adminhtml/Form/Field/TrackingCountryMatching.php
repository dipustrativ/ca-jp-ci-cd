<?php
namespace Evalent\MatrixRateMods\Block\Adminhtml\Form\Field;

class TrackingCountryMatching extends \Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray{

    /** @var \Evalent\MatrixRateMods\Block\Adminhtml\Form\Field\TrackingCountries */
    protected $countries;

    protected function getCountriesRenderer(){
        if (!$this->countries){
            $this->countries = $this->getLayout()->createBlock(
                '\Evalent\MatrixRateMods\Block\Adminhtml\Form\Field\TrackingCountries',
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->countries;
    }

    /**
     * Prepare to render
     *
     * @return void
     */
    protected function _prepareToRender()
    {
        // Override in descendants to add columns, change add button label etc
        $this->addColumn('country_code',
            [
                'label' => __('Country'),
                'renderer' => $this->getCountriesRenderer()
            ]);
        $this->addColumn('tracking_link',
            [
                'label' => __('Link'),
                'class' => 'required-entry'
            ]);
        $this->_addAfter = false;
    }

    /**
     * Prepare existing row data object.
     *
     * @param \Magento\Framework\DataObject $row
     * @return void
     */
    protected function _prepareArrayRow(\Magento\Framework\DataObject $row)
    {
        $options = [];

        $customAttribute = $row->getData('country_code');
        $key = 'option_' . $this->getCountriesRenderer()->calcOptionHash($customAttribute);
        $options[$key] = 'selected="selected"';

        $row->setData('option_extra_attrs', $options);
    }
}
<?php

namespace Drupal\comment_spam\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Defines a form that configures forms module settings.
 */
class ModuleConfigurationForm extends ConfigFormBase {

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return 'comment_spam_admin_settings';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames() {
        return [
            'comment_spam.settings',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {
        $form = parent::buildForm($form, $form_state);
        //Get settings for module
        $config = $this->config('comment_spam.settings');


        //Display current webforms with Spam Filter
        $def_web = $config->get('webform_list');
        $webform_list = array_diff($def_web,array('0'));

        $form['webforms00']['#markup'] = t('<p>Webforms included: '.join(", ",$webform_list).'</p>');

        //Get default list and full current list of words to filter by
        $defaultWords = $config->get('default_list');
        $myWords = $config->get('custom_list');
        //Whether or not the default list is included
        $include_default = $config->get('include_default_list');

        //Obtain and Display default list
        $default_rows = "";
        foreach($defaultWords as $badWord){
            $default_rows.=$badWord."<br>";
        }

        $form['default_list'] = array(
            '#type' => 'radios',
            '#title' => $this
                ->t('Include default list?'),
            '#default_value' => $include_default,
            '#options' => array(
                0 => $this
                    ->t('Don\'t include'),
                1 => $this
                    ->t('Include'),
            ),
        );

        $form['text_details']['#markup'] = t('<details><summary>Click here to view the default list</summary>'.$default_rows.'</details>');
        $form['text_list_title']['#markup'] = t('<h3>Current list of banned words/phrases <br> (select checkbox beside a word to remove it)</h3>');

        //Obtain and Display List of current list of banned words
        $current_rows[]=array();

        $count=1;
        foreach($myWords as $badWord){
            $options[$badWord]= ['words' => $badWord,];
            $count++;
            //$current_rows[] = array($badWord);
        }
        $header = [
            'words' => $this
                ->t('Bad word'),
        ];
        //Display in tableselect to allow selection of words for removal
        $form['table'] = array(
            '#type' => 'tableselect',
            '#header' => $header,
            '#options' => $options,
            '#empty' => $this
                ->t('You have not added any words yet'),
        );
        //Remove Selected words
        $form['update_list'] = array(
            '#name' => 'update_list',
            '#type' => 'submit',
            '#value' => t('Remove Selected Words'),
            '#submit' => array([$this, 'updateList']),
        );

/*        $form['block_pattern'] = array(
            '#type' => 'radios',
            '#title' => $this
                ->t('Block individual words or any sequence of letters that match a bad word?'),
            '#default_value' => 0,
            '#options' => array(
                0 => $this
                    ->t('Only block words (recommended)'),
                1 => $this
                    ->t('Block Sequence'),
            ),
        );

*/
        $style="border: red solid; border-radius: 5px; padding:10px;";
        $form['text_warning']['#markup'] =
                t('<br><br><div style="'.$style.'"><h3 style="color: red">WARNING!</h3><br> Blocking sequence may produce unexpected results!<br>
           For example, banning \'ass\' will also block comments with the word \'classic\'. Be careful with what you ban!</div><br> ');
            $form['text_info']['#markup'] = t('<p>Not case sensitive</p>');

            //Text area to add custom words to ban list
            $form['addWords'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Add a word or words separated by line'),
            ];

        $form['actions']['submit']['#value'] = [
            '#type' => 'submit',
            '#value' => $this->t('words to list'),
        ];

        return $form;
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * Remove words selected in tableselect from config 'custom_list'
     */
    public function updateList(array &$form, FormStateInterface &$form_state) {
        $config = $this->config('comment_spam.settings');
        $result=$config->get('custom_list');
        $wordsToRemove = array_filter($form_state->getValue('table'));


        if(!empty($wordsToRemove)){
            foreach ($wordsToRemove as $del_word){
                if (($key = array_search($del_word, $result)) !== false) {
                    unset($result[$key]);
                }
            }
        }
        $config->set('custom_list',$result)->save();
        $form_state->setRebuild();
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state) {
        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state){
        //Obtain values submitted in form
        $wordsToAdd = $form_state->getValue('addWords');
        $default_list_include=$form_state->getValue('default_list');
        $wordsToRemove = array_filter($form_state->getValue('table'));
        //Webforms selected in form
        $webform_add = $form_state->getValue('entities');

        //Obtain values in config before submitting form
        $config = $this->config('comment_spam.settings');
        $defaultWords = $config->get('default_list');
        $result=$config->get('custom_list');

        //If the custom_list from config is not empty, merge old with new
        if(!empty($result)){
            //Include default list
            if($default_list_include==1){
                $result=array_merge($result,$defaultWords);
            }else{
                $result=array_diff($result,$defaultWords);
            }
            //Add words from textarea
            if(!empty($wordsToAdd)) {
                $wordsToAdd = explode("\n", $wordsToAdd);
                $result = array_merge($result, $wordsToAdd);
            }
        }
        //Else if empty, no need to merge
        else{
            if($default_list_include==1){
                $result=$defaultWords;
            }

            if(!empty($wordsToAdd)){
                $wordsToAdd =explode("\n", $wordsToAdd);
                $result=$wordsToAdd;
            }
        }

        if(!empty($wordsToRemove)){
            foreach ($wordsToRemove as $del_word){
                if (($key = array_search($del_word, $result)) !== false) {
                    unset($result[$key]);
                }
            }
        }

        //save results in config
        $config->set('custom_list',$result)->save();
        $config->set('include_default_list',$default_list_include)->save();

        //The spam_flag field to be added to webforms
        $hidden_field = "\nspam_flag:\n  '#type': checkbox\n  '#title': spam_flag\n  '#disabled': true\n  '#wrapper_attributes':\n    class:\n      - hidden";

    }


}
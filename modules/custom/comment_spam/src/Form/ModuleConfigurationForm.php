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
        $config = $this->config('comment_spam.settings');
        $defaultWords = $config->get('default_list');
        $myWords = $config->get('custom_list');
        $include_default = $config->get('include_default_list');

        $default_rows = "";
        foreach($defaultWords as $badWord){
            $default_rows.=$badWord."<br>";
        }
        if($include_default==1){
            $form['default_list'] = array(
                '#type' => 'radios',
                '#title' => $this
                    ->t('Include default list?'),
                '#default_value' => 1,
                '#options' => array(
                    0 => $this
                        ->t('Don\'t include'),
                    1 => $this
                        ->t('Include'),
                ),
            );
        }else{
            $form['default_list'] = array(
                '#type' => 'radios',
                '#title' => $this
                    ->t('Include default list?'),
                '#default_value' => 0,
                '#options' => array(
                    0 => $this
                        ->t('Don\'t include'),
                    1 => $this
                        ->t('Include'),
                ),
            );
        }

        $form['text_details']['#markup'] = t('<details><summary>Click here to view the default list</summary>'.$default_rows.'</details>');
        $form['text_list_title']['#markup'] = t('<h3>Current list of banned words/phrases <br> (select checkbox beside a word to remove it)</h3>');


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

        $form['table'] = array(
            '#type' => 'tableselect',
            '#header' => $header,
            '#options' => $options,
            '#empty' => $this
                ->t('You have not added any words yet'),
        );
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

*/      $style="border: red solid; border-radius: 5px; padding:10px;";
        $form['text_warning']['#markup'] =
                t('<br><br><div style="'.$style.'"><h3 style="color: red">WARNING!</h3><br> Blocking sequence may produce unexpected results!<br>
           For example, banning \'ass\' will also block comments with the word \'classic\'. Be careful with what you ban!</div><br> ');
            $form['text_info']['#markup'] = t('<p>Not case sensitive</p>');

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
        $wordsToAdd = $form_state->getValue('addWords');
        $default_list_include=$form_state->getValue('default_list');
        $wordsToRemove = array_filter($form_state->getValue('table'));
        $config = $this->config('comment_spam.settings');
        $defaultWords = $config->get('default_list');

        $result=$config->get('custom_list');
        if(!empty($result)){
            if($default_list_include==1){
                $result=array_merge($result,$defaultWords);
            }else{
                $result=array_diff($result,$defaultWords);
            }

            if(!empty($wordsToAdd)){
                $wordsToAdd =explode("\n", $wordsToAdd);
                $result=array_merge($result,$wordsToAdd);
            }
        }else{
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

        $config->set('custom_list',$result)->save();
        $config->set('include_default_list',$default_list_include)->save();

    }

}
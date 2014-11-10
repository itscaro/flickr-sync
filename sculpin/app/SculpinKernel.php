<?php


class SculpinKernel extends \Sculpin\Bundle\SculpinBundle\HttpKernel\DefaultKernel
{

    protected function getKernelParameters()
    {
        $return = parent::getKernelParameters();
        $return = array_merge(
            $return, array(
                'sculpin.output_dir' => $return['sculpin.project_dir'] . '/../'
            )
        );

        return $return;
    }

}

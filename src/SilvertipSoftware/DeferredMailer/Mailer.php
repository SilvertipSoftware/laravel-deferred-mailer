<?php namespace SilvertipSoftware\DeferredMailer;

class Mailer {

    public function getViewNameFor( $method ) {
        $clazz = get_class($this);
        $viewSubDir = implode( '.', array_filter(array_map(
            function($fragment) { return \Str::snake($fragment); }, explode( '\\', $clazz )
        )));
        return $viewSubDir;
    }

    public function mail( $emailParams, $viewData = array() ) {
        $view = $this->view_subdir . '.' . $this->view_name;
        \Mail::send( $view, array_merge( $viewData, array('base_url'=>$this->base_url) ), function($m) use ($emailParams) {
            foreach( $emailParams as $key => $value ) {
                call_user_func_array( array($m, $key), (array)$value );
            }
        });
        \Log::info('Sent mail to '. $emailParams['to']);
    }

    public function handleMailJob( $job, $data ) {
        $clazz = $data['clazz'];
        $method = $data['method'];

        try {
            $real_mailer = new $clazz;
            $real_mailer->view_subdir = $real_mailer->getViewNameFor( $method );
            $real_mailer->view_name = \Str::snake( $method );
            $real_mailer->base_url = $data['base_url'];

            call_user_func_array( array($real_mailer, '_'.$method), $data['parameters'] );
            $job->delete();
        } catch ( \Exception $e ) {
            $should_give_up = ( $job->attempts() > \Config::get('mail.max_attempts') );
            \Log::error( 'Error trying to send email', array(
                'exception' => get_class( $e ),
                'mailer' => $clazz.'_'.$method,
                'reason' => $e->getMessage(),
                'giving_up' => $should_give_up
            ));
            if  ( $should_give_up ) {
                $job->delete();
            } else {
                $job->release();
            }
        }
    }

    public static function __callStatic( $method, $parameters ) {
        $clazz = get_called_class();

        if(\Config::get('queue.default') == 'sync') {
            foreach($parameters as $param) {
                // If param is laravel model throw error
                if($param instanceof \Eloquent) {
                    throw new \InvalidArgumentException('Email parameters cannot be laravel models');   
                }
            }
        }
    
        \Queue::push( 'Mailer@handleMailJob', array(
            'clazz' => $clazz,
            'method' => $method,
            'base_url' => \URL::to('/'),
            'parameters' => $parameters
        ));
    }
}
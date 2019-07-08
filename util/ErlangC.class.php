<?php

/** http://www.mitan.co.uk/erlang/elgcmath.htm */

namespace CCS\util;

class ErlangC
{
    private $maxAgents;
    private $maxCalls = 4500; // empiric value

    public function __construct($maxAgents = 200)
    {
        $this->maxAgents = $maxAgents;
    }

    /** Оптимальное кол-во операторов для заданного
     *  кол-ва звонков $calls
     *  на интервале времени $intervalLength минут
     *  со средней продолжительностью звонка $callDuration минут
     *  заданной вероятности попадания в время SLA $targetSLATime c вероятностью $targetSLALevel (0...1)
     **/
    public function optimalAgents($calls, $intervalLength, $callDuration, $targetSLALevel, $targetSLATime)
    {
        if ($calls > $this->maxCalls) {
            return -1;
        }
        for ($i = 1; $i <= $this->maxAgents; $i++) {
            $agents = $i;
            $probSLA = $this->probabilitySLA($agents, $calls, $intervalLength, $callDuration, $targetSLATime);
            if ($probSLA >= $targetSLALevel) {
                return $i;
            }
        }
        return -1;
    }

    /** The term "traffic intensity" comes from the original application of Erlang-C,
    * which was for telephone networks, and the volume of calls was described as the "traffic".
    * We need to calculate the traffic intensity as a preliminary step to the rest of the calculations.
    */
    public function trafficIntensity($calls, $intervalLength, $callDuration)
    {
        return ($calls / ($intervalLength * 60)) * ($callDuration * 60);
    }

    /** Having calculated EC(m,u) ($this->ErlangC) it is quite easy to calculate the average waiting
     * time for a call, which is often referred to as the "Average Speed of Answer" or ASA 
     */
    public function averageSpeedOfAnswer($agents, $calls, $intervalLength, $callDuration)
    {
        return \round(($this->erlangC($agents, $calls, $intervalLength, $callDuration) * ($callDuration * 60)
        / ($agents * (1 - $this->agentOccupancy($agents, $calls, $intervalLength, $callDuration))))*100)/100;
    }

    /** Calculate service level
    * Frequently we want to calculate the probability that a call will be answered in less than a target waiting time.
    * The formula for this is given here. Remember that, again, the probability will be on the scale 0 to 1 and should
    * be multiplied by 100 to express it as a percentage.
    */
    public function probabilitySLA($agents, $calls, $intervalLength, $callDuration, $targetSLATime)
    {
        $eDegree = -($agents - $this->trafficIntensity($calls, $intervalLength, $callDuration)) * $targetSLATime/$callDuration;
        return 1 - $this->erlangC($agents, $calls, $intervalLength, $callDuration) * pow(M_E, $eDegree);
    }

    /** EC(m,u) is the probability that a call is not answered immediately, and has to wait.
    * This is a probability between 0 and 1, and to express it as a percentage of calls we multiply by 100%.
    */
    public function erlangC($agents, $calls, $intervalLength, $callDuration)
    {
        $p1 = $this->poisson($agents, $this->trafficIntensity($calls, $intervalLength, $callDuration));
        $agentOcc = $this->agentOccupancy($agents, $calls, $intervalLength, $callDuration);
        $poisCum = $this->poissonCumul($agents - 1, $this->trafficIntensity($calls, $intervalLength, $callDuration));
        // print_r(func_get_args());
        return $p1 / ($p1 + (1 - $agentOcc) * $poisCum);
    }

    /** Calculate agent occupancy
    * The agent occupancy, or utilisation, is now calculated by dividing the traffic intensity by the number of agents.
    * The agent occupancy will be between 0 and 1. If it is not less than 1 then the agents are overloaded, and the
    * Erlang-C calculations are not meaningful, and may give negative waiting times.
    */
    public function agentOccupancy($agents, $calls, $intervalLength, $callDuration)
    {
        return $this->trafficIntensity($calls, $intervalLength, $callDuration) / $agents;
    }

    /** Poisson distribution */
    public function poisson($idealSuccesses, $theMean)
    {
        if ($idealSuccesses <= 0) {
            return 0;
        }

        $numerator = pow($theMean, $idealSuccesses) * (pow(M_E, ($theMean * -1)));
        $denominator = $this->factorial($idealSuccesses);
        return $numerator / $denominator;
    }

    /** Poisson cumulative distribution */
    public function poissonCumul($idealSuccesses, $theMean)
    {
        $daReturn = 0;
        for ($i = 0; $i <= $idealSuccesses; $i++) {
            $daReturn = $daReturn + $this->poisson($i, $theMean);
        }
        return $daReturn;
    }

    public function factorial($input)
    {
        if ($input == 0) {
            return 1;
        }
        return $input * $this->factorial($input - 1);
    }

    /** @deprecated
     *  !!! Странная и косячная формула. Оставлена "на всякий случай". Расчетное кол-во операторов не зависит
     *  от заданной вероятности попадание в окно target SLA, а зависит только от вероятности немедленного ответа.
     *  Это не совсем то, что нужно. Вернее совсем не то.
     *
     *  Оптимальное кол-во операторов для заданного
     *  кол-ва звонков $calls
     *  заданно вероятности немедленного ответа $idealImmediateAnswer (0...1)
     *  на интервале времени $intervalLength минут
     *  со средней продолжительностью звонка $callDuration минут
     **/
    private function optimalStaff($calls, $idealImmediateAnswer, $intervalLength, $callDuration)
    {
        for ($i = 1; $i <= 500; $i++) {
            $agents = $i;
            if ($this->immediateAnswer($agents, $calls, $intervalLength, $callDuration) > $idealImmediateAnswer) {
                return $i - 1;
            }
        }
        return -1;
    }
    /** @deprecated
    * !!! Не рекомендуется использовать. Считает не пойми чо.
    */
    public function immediateAnswer($agents, $calls, $intervalLength, $callDuration)
    {
        return round((1 - $this->erlangC($agents, $calls, $intervalLength, $callDuration) * 100)*100)/100;
    }
}
